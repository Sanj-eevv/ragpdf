<?php

use App\Enums\ChunkingStrategy;
use App\Enums\DocumentStatus;
use App\Enums\EvaluationRunStatus;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Models\Query;
use App\Models\RagasEvaluation;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasExperimentRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    // Echoes back one 384-dim vector per input, so every chunk gets embedded
    // regardless of how many chunks the real chunker produces.
    Http::fake([
        '*/embed' => fn ($request) => Http::response([
            'embeddings' => collect($request->data()['inputs'] ?? [])
                ->map(fn () => array_fill(0, 384, 0.1))
                ->all(),
        ]),
    ]);
});

test('resolveDocument auto-ingests a static file referenced by document_path, without a pre-existing Document', function () {
    expect(Document::count())->toBe(0);

    $document = app(RagasExperimentRunner::class)->resolveDocument([
        'document_path' => 'tests/Fixtures/sample.pdf',
        'question' => 'irrelevant',
    ]);

    expect($document)->not->toBeNull()
        ->and($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->original_filename)->toBe('sample.pdf')
        ->and($document->chunks()->count())->toBeGreaterThan(0)
        ->and($document->chunks()->whereNull('embedding')->count())->toBe(0);
});

test('resolveDocument reuses the already-ingested static document on subsequent calls', function () {
    $runner = app(RagasExperimentRunner::class);
    $entry = ['document_path' => 'tests/Fixtures/sample.pdf', 'question' => 'irrelevant'];

    $first = $runner->resolveDocument($entry);
    $second = $runner->resolveDocument($entry);

    expect(Document::count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

test('resolveDocument throws when the referenced static file does not exist', function () {
    app(RagasExperimentRunner::class)->resolveDocument([
        'document_path' => 'tests/Fixtures/does-not-exist.pdf',
        'question' => 'irrelevant',
    ]);
})->throws(RuntimeException::class);

test('resolveDocument still supports document_filename against an already-uploaded ready document', function () {
    $document = Document::factory()->create([
        'original_filename' => 'existing.pdf',
        'status' => DocumentStatus::Ready,
    ]);

    $resolved = app(RagasExperimentRunner::class)->resolveDocument([
        'document_filename' => 'existing.pdf',
        'question' => 'irrelevant',
    ]);

    expect($resolved->id)->toBe($document->id);
});

test('resolveDocument returns null when document_filename has no matching ready document', function () {
    $resolved = app(RagasExperimentRunner::class)->resolveDocument([
        'document_filename' => 'never-uploaded.pdf',
        'question' => 'irrelevant',
    ]);

    expect($resolved)->toBeNull();
});

test('refreshResults marks the run completed with no missing units when every row is present', function () {
    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Running, 'total' => 2]);

    collect(range(1, 2))->each(function () use ($run) {
        $query = Query::factory()->create(['ragas_evaluation_run_id' => $run->id]);
        RagasEvaluation::factory()->for($query, 'queryRecord')->create();
    });

    app(RagasExperimentRunner::class)->refreshResults($run);

    $run->refresh();
    expect($run->status)->toBe(EvaluationRunStatus::Completed)
        ->and($run->completed)->toBe(2)
        ->and($run->error_message)->toBeNull()
        ->and($run->summary)->not->toBeEmpty()
        ->and($run->csv_path)->not->toBeNull();
});

test('refreshResults reports how many units are still missing', function () {
    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Running, 'total' => 3]);

    $query = Query::factory()->create(['ragas_evaluation_run_id' => $run->id]);
    RagasEvaluation::factory()->for($query, 'queryRecord')->create();

    app(RagasExperimentRunner::class)->refreshResults($run);

    $run->refresh();
    expect($run->completed)->toBe(1)
        ->and($run->error_message)->toContain('2 of 3 question-configs are missing');
});

test('refreshResults is idempotent and self-heals once a previously-missing unit shows up', function () {
    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Running, 'total' => 1]);
    $runner = app(RagasExperimentRunner::class);

    $runner->refreshResults($run);
    $run->refresh();
    expect($run->completed)->toBe(0)
        ->and($run->error_message)->toContain('1 of 1');

    $query = Query::factory()->create(['ragas_evaluation_run_id' => $run->id]);
    RagasEvaluation::factory()->for($query, 'queryRecord')->create();

    $runner->refreshResults($run->fresh());

    $run->refresh();
    expect($run->completed)->toBe(1)
        ->and($run->error_message)->toBeNull();
});

test('detailsByQuestion groups results by question, each with its own per-config answers and scores', function () {
    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Completed, 'total' => 3]);

    $queryA1 = Query::factory()->create([
        'ragas_evaluation_run_id' => $run->id,
        'question' => 'What is Laravel?',
        'answer' => 'A PHP framework.',
        'ground_truth_answer' => 'Laravel is a PHP web framework.',
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense,
        'reranked' => false,
    ]);
    RagasEvaluation::factory()->for($queryA1, 'queryRecord')->create(['context_precision' => 0.5]);

    $queryA2 = Query::factory()->create([
        'ragas_evaluation_run_id' => $run->id,
        'question' => 'What is Laravel?',
        'answer' => 'A web framework built on PHP.',
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense,
        'reranked' => true,
    ]);
    RagasEvaluation::factory()->for($queryA2, 'queryRecord')->create(['context_precision' => 0.9]);

    $queryB = Query::factory()->create([
        'ragas_evaluation_run_id' => $run->id,
        'question' => 'Who created Laravel?',
        'answer' => 'Taylor Otwell.',
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense,
        'reranked' => false,
    ]);
    RagasEvaluation::factory()->for($queryB, 'queryRecord')->create();

    $details = app(RagasExperimentRunner::class)->detailsByQuestion($run);

    expect($details)->toHaveCount(2);

    $laravelDetail = collect($details)->firstWhere('question', 'What is Laravel?');
    expect($laravelDetail['ground_truth_answer'])->toBe('Laravel is a PHP web framework.')
        ->and($laravelDetail['configs'])->toHaveCount(2)
        ->and(collect($laravelDetail['configs'])->pluck('answer')->all())->toBe([
            'A PHP framework.',
            'A web framework built on PHP.',
        ])
        ->and(collect($laravelDetail['configs'])->pluck('context_precision')->all())->toBe([0.5, 0.9]);

    $creatorDetail = collect($details)->firstWhere('question', 'Who created Laravel?');
    expect($creatorDetail['configs'])->toHaveCount(1)
        ->and($creatorDetail['configs'][0]['answer'])->toBe('Taylor Otwell.');
});
