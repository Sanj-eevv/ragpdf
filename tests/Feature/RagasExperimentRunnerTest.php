<?php

use App\Enums\DocumentStatus;
use App\Enums\EvaluationRunStatus;
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
