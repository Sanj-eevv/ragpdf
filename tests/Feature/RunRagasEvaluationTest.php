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
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

beforeEach(function () {
    Storage::fake('local');

    $this->document = Document::factory()->create(['original_filename' => 'test-doc.pdf']);
    DocumentChunk::factory()->for($this->document)->create([
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    Embeddings::fake();
    Reranking::fake();
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
