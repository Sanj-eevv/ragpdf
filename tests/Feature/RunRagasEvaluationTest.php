<?php

use App\Ai\Agents\Judges\AnswerRelevanceJudge;
use App\Ai\Agents\Judges\ContextPrecisionJudge;
use App\Ai\Agents\Judges\ContextRecallJudge;
use App\Ai\Agents\Judges\FaithfulnessJudge;
use App\Ai\Agents\RagAnswerAgent;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Query as QueryModel;
use App\Models\RagasEvaluation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->document = Document::factory()->create(['original_filename' => 'test-doc.pdf']);
    DocumentChunk::factory()->for($this->document)->create([
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    Http::fake([
        // Echoes back whatever documents were sent, in the same order, so the
        // reranked configs in the matrix have a valid index to map back onto
        // regardless of how many chunks were retrieved.
        '*/rerank' => fn ($request) => Http::response(
            collect($request->data()['documents'] ?? [])
                ->values()
                ->map(fn ($document, $index) => ['index' => $index, 'document' => $document, 'score' => 1.0])
                ->all()
        ),
        // One fixed 384-dim vector per input — the test's NO_SIMILARITY_THRESHOLD
        // retrieval doesn't rank on distance, so the exact value doesn't matter.
        '*/embed' => fn ($request) => Http::response([
            'embeddings' => collect($request->data()['inputs'] ?? [])
                ->map(fn () => array_fill(0, 384, 0.1))
                ->all(),
        ]),
    ]);
    RagAnswerAgent::fake(['Antibiotics and rest.']);
    ContextPrecisionJudge::fake([['score' => 0.8, 'reasoning' => 'ok']]);
    ContextRecallJudge::fake([['score' => 0.9, 'missing_facts' => []]]);
    FaithfulnessJudge::fake([['score' => 1.0, 'unsupported_claims' => []]]);
    AnswerRelevanceJudge::fake([['score' => 0.7, 'reasoning' => 'ok']]);
});

test('it evaluates every question in the dataset against the requested config, skipping missing documents', function () {
    $this->artisan('rag:evaluate', [
        '--dataset' => 'tests/Fixtures/mini_ragas_dataset.json',
        '--configs' => 'tokens_500_dense_no_rerank',
    ])
        ->expectsOutputToContain('Skipping "This document was never uploaded." — no ready document named [missing-doc.pdf].')
        ->assertExitCode(0);

    // 2 valid questions (both against test-doc.pdf) x 1 config = 2 runs.
    expect(QueryModel::count())->toBe(2)
        ->and(RagasEvaluation::count())->toBe(2);

    $answerable = QueryModel::where('question', 'How is pneumonia treated?')->sole();
    expect($answerable->ragasEvaluation->context_recall)->toBe(0.9);

    $unanswerable = QueryModel::where('question', 'What is the capital of France?')->sole();
    expect($unanswerable->ragasEvaluation->context_recall)->toBeNull();

    $csvFiles = Storage::disk('local')->allFiles('eval');
    expect($csvFiles)->toHaveCount(1)
        ->and($csvFiles[0])->toEndWith('.csv');

    $csv = Storage::disk('local')->get($csvFiles[0]);
    expect($csv)->toContain('config_id')
        ->toContain('tokens_500_dense_no_rerank');
});

test('it runs the full 8-config matrix when no --configs filter is given', function () {
    $this->artisan('rag:evaluate', [
        '--dataset' => 'tests/Fixtures/mini_ragas_dataset.json',
        '--limit' => 1,
    ])->assertExitCode(0);

    // 1 question (limit=1) x 8 configs = 8 runs.
    expect(QueryModel::count())->toBe(8);
});

test('it fails with a helpful message when --configs matches nothing', function () {
    $this->artisan('rag:evaluate', [
        '--dataset' => 'tests/Fixtures/mini_ragas_dataset.json',
        '--configs' => 'not_a_real_config',
    ])
        ->expectsOutputToContain('No configs matched. Available:')
        ->assertExitCode(1);

    expect(QueryModel::count())->toBe(0);
});

test('it fails cleanly when the dataset file does not exist', function () {
    $this->artisan('rag:evaluate', [
        '--dataset' => 'tests/Fixtures/does_not_exist.json',
    ])
        ->expectsOutputToContain('Dataset file not found')
        ->assertExitCode(1);
});
