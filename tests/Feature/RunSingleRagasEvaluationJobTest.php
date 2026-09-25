<?php

use App\Ai\Agents\Judges\AnswerRelevanceJudge;
use App\Ai\Agents\Judges\ContextPrecisionJudge;
use App\Ai\Agents\Judges\ContextRecallJudge;
use App\Ai\Agents\Judges\FaithfulnessJudge;
use App\Ai\Agents\RagAnswerAgent;
use App\Enums\ChunkingStrategy;
use App\Enums\EvaluationRunStatus;
use App\Enums\RetrievalAlgorithm;
use App\Jobs\RunSingleRagasEvaluationJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Query;
use App\Models\RagasEvaluation;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasEvaluator;
use App\Services\Evaluation\RagasExperimentRunner;
use App\Services\QueryPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::fake([
        '*/embed' => fn ($request) => Http::response([
            'embeddings' => collect($request->data()['inputs'] ?? [])
                ->map(fn () => array_fill(0, 384, 0.1))
                ->all(),
        ]),
    ]);
    RagAnswerAgent::fake(['Antibiotics and rest.']);
    ContextPrecisionJudge::fake([['verdicts' => [['rank' => 0, 'relevant' => true, 'reason' => 'ok']]]]);
    ContextRecallJudge::fake([['statements' => [['statement' => 'x', 'attributed' => true, 'reason' => 'ok']]]]);
    FaithfulnessJudge::fake([['claims' => []]]);
    AnswerRelevanceJudge::fake([['requirements' => [['requirement' => 'x', 'addressed' => true, 'reason' => 'ok']]]]);
});

function makeRagasJob(RagasEvaluationRun $run, Document $document, string $question = 'How is pneumonia treated?', ?string $groundTruth = 'Antibiotics and rest.'): RunSingleRagasEvaluationJob
{
    return new RunSingleRagasEvaluationJob(
        $run,
        $document->id,
        $question,
        $groundTruth,
        ChunkingStrategy::Tokens500,
        RetrievalAlgorithm::Dense,
        false,
    );
}

test('the job evaluates its one question/config unit, tags the query with the run, and records progress', function () {
    $document = Document::factory()->create();
    DocumentChunk::factory()->for($document)->create([
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 1,
    ]);

    makeRagasJob($run, $document)->handle(app(QueryPipeline::class), app(RagasEvaluator::class), app(RagasExperimentRunner::class));

    expect($run->fresh()->completed)->toBe(1);

    $query = Query::sole();
    expect($query->ragas_evaluation_run_id)->toBe($run->id)
        ->and($query->ground_truth_answer)->toBe('Antibiotics and rest.')
        ->and($query->ragasEvaluation)->not->toBeNull()
        ->and($query->ragasEvaluation->context_precision)->toBe(1.0);
});

test('the job does nothing when its batch has already been cancelled', function () {
    $document = Document::factory()->create();
    DocumentChunk::factory()->for($document)->create();

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 1,
    ]);

    [$job, $batch] = makeRagasJob($run, $document, 'A question', null)->withFakeBatch();
    $batch->cancel();

    $job->handle(app(QueryPipeline::class), app(RagasEvaluator::class), app(RagasExperimentRunner::class));

    expect(Query::count())->toBe(0)
        ->and($run->fresh()->completed)->toBe(0);

    Http::assertNothingSent();
});

test('failed() reports the exception but does not count toward completed, so a later retry cannot double-count', function () {
    $document = Document::factory()->create();
    DocumentChunk::factory()->for($document)->create();

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 1,
    ]);

    makeRagasJob($run, $document, 'A question', null)->failed(new RuntimeException('embed service unavailable'));

    expect($run->fresh()->completed)->toBe(0);
});

test('handle is a no-op when a Query and its evaluation already exist for this exact unit (a fully-succeeded retry)', function () {
    $document = Document::factory()->create();

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 1,
        'completed' => 1,
    ]);

    $existingQuery = Query::factory()->create([
        'document_id' => $document->id,
        'ragas_evaluation_run_id' => $run->id,
        'question' => 'How is pneumonia treated?',
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense,
        'reranked' => false,
    ]);
    RagasEvaluation::factory()->for($existingQuery, 'queryRecord')->create();

    makeRagasJob($run, $document)->handle(app(QueryPipeline::class), app(RagasEvaluator::class), app(RagasExperimentRunner::class));

    expect(Query::count())->toBe(1)
        ->and(RagasEvaluation::count())->toBe(1)
        ->and($run->fresh()->completed)->toBe(1);

    Http::assertNothingSent();
});

test('handle reuses the existing Query when the pipeline already succeeded but evaluation had not (a partially-succeeded retry)', function () {
    $document = Document::factory()->create();

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 1,
    ]);

    $existingQuery = Query::factory()->create([
        'document_id' => $document->id,
        'ragas_evaluation_run_id' => $run->id,
        'question' => 'How is pneumonia treated?',
        'answer' => 'Antibiotics and rest.',
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense,
        'reranked' => false,
        'retrieved_chunk_ids' => [],
    ]);

    makeRagasJob($run, $document)->handle(app(QueryPipeline::class), app(RagasEvaluator::class), app(RagasExperimentRunner::class));

    expect(Query::count())->toBe(1);

    expect($existingQuery->fresh()->ragasEvaluation)->not->toBeNull()
        ->and($run->fresh()->completed)->toBe(1);

    // The pipeline itself must not have been re-run — no retrieval/embed
    // HTTP call for a question that already had an answer.
    Http::assertNothingSent();
});

test('a unit that succeeds after its run already completed (e.g. a Horizon retry) refreshes the run results', function () {
    $document = Document::factory()->create();
    DocumentChunk::factory()->for($document)->create([
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    // Simulate: this unit originally failed, the batch finished without it,
    // and refreshResults() already ran once leaving the run "completed" with
    // its one unit missing.
    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Completed,
        'total' => 1,
        'completed' => 0,
        'summary' => [],
        'error_message' => '1 of 1 question-configs are missing — check the Horizon failed jobs dashboard and retry them; this run\'s results will refresh automatically.',
    ]);

    makeRagasJob($run, $document)->handle(app(QueryPipeline::class), app(RagasEvaluator::class), app(RagasExperimentRunner::class));

    $run->refresh();
    expect($run->status)->toBe(EvaluationRunStatus::Completed)
        ->and($run->completed)->toBe(1)
        ->and($run->error_message)->toBeNull()
        ->and($run->summary)->toHaveCount(1)
        ->and($run->csv_path)->not->toBeNull();
});
