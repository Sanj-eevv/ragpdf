<?php

use App\Enums\DocumentStatus;
use App\Enums\EvaluationRunStatus;
use App\Jobs\RunSingleRagasEvaluationJob;
use App\Models\Document;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasExperimentRunner;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.ragas.dataset_path' => 'tests/Fixtures/mini_ragas_dataset.json']);
});

test('index renders the latest evaluation run', function () {
    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Completed,
        'total' => 24,
        'completed' => 24,
        'summary' => [['config_id' => 'tokens_500_dense_no_rerank', 'n' => 3]],
    ]);

    $response = $this->get(route('evaluation.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('Evaluation/Index')
        ->where('run.id', $run->id)
        ->where('run.status', 'completed')
        // mini_ragas_dataset.json has 3 rows.
        ->has('questions', 3)
        ->where('questions.0.question', 'How is pneumonia treated?'));
});

test('index renders no run when none exist yet', function () {
    $response = $this->get(route('evaluation.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('Evaluation/Index')
        ->where('run', null));
});

test('index renders an empty question list when the dataset file is missing', function () {
    config(['services.ragas.dataset_path' => 'does/not/exist.json']);

    $response = $this->get(route('evaluation.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('Evaluation/Index')
        ->where('questions', []));
});

test('store dispatches one batched job per question x config unit, skipping questions with no ready document', function () {
    Bus::fake();

    Document::factory()->create(['original_filename' => 'test-doc.pdf', 'status' => DocumentStatus::Ready]);

    $response = $this->post(route('evaluation.store'));

    $response->assertRedirect(route('evaluation.index'));

    $run = RagasEvaluationRun::sole();
    // mini_ragas_dataset.json has 2 questions against test-doc.pdf x 8 configs;
    // the 3rd question (missing-doc.pdf) has no matching document and is skipped.
    $expectedUnits = 2 * count(RagasExperimentRunner::allConfigs());

    expect($run->status)->toBe(EvaluationRunStatus::Running)
        ->and($run->completed)->toBe(0)
        ->and($run->total)->toBe($expectedUnits)
        ->and($run->batch_id)->not->toBeNull();

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === $expectedUnits
        && $batch->jobs->every(fn ($job) => $job instanceof RunSingleRagasEvaluationJob && $job->run->is($run)));
});

test('store staggers each dispatched unit job by 5 seconds so they are not all released at once', function () {
    Bus::fake();

    Document::factory()->create(['original_filename' => 'test-doc.pdf', 'status' => DocumentStatus::Ready]);

    $this->post(route('evaluation.store'));

    Bus::assertBatched(function (PendingBatch $batch) {
        $delays = $batch->jobs->pluck('delay')->values();

        if ($delays->count() < 2) {
            return false;
        }

        for ($i = 1; $i < $delays->count(); $i++) {
            // diffInSeconds() returns sub-second precision (real execution
            // time elapses between each now() call in the loop), so allow a
            // small tolerance rather than requiring an exact 5.0.
            if (abs(abs($delays[$i]->diffInSeconds($delays[$i - 1])) - 5) > 0.5) {
                return false;
            }
        }

        return true;
    });
});

test('store completes immediately with an empty summary when nothing in the dataset matches a ready document', function () {
    Bus::fake();

    $response = $this->post(route('evaluation.store'));

    $response->assertRedirect(route('evaluation.index'));

    $run = RagasEvaluationRun::sole();
    expect($run->status)->toBe(EvaluationRunStatus::Completed)
        ->and($run->total)->toBe(0)
        ->and($run->summary)->toBe([]);

    Bus::assertNothingBatched();
});

test('store refuses to start a second run while one is already active', function () {
    Bus::fake();

    RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 24,
        'completed' => 5,
    ]);

    $response = $this->post(route('evaluation.store'));

    $response->assertRedirect()
        ->assertSessionHasErrors('evaluation');

    expect(RagasEvaluationRun::count())->toBe(1);
    Bus::assertNothingBatched();
});

test('download streams the run csv', function () {
    Storage::fake('local');
    Storage::disk('local')->put('eval/results_test.csv', "a,b\n1,2\n");

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Completed,
        'total' => 1,
        'completed' => 1,
        'csv_path' => 'eval/results_test.csv',
    ]);

    $response = $this->get(route('evaluation.download', $run));

    $response->assertOk();
});

test('download 404s when the run has no csv yet', function () {
    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 1,
        'completed' => 0,
    ]);

    $response = $this->get(route('evaluation.download', $run));

    $response->assertNotFound();
});

test('cancel stops a running evaluation and unblocks starting a new one', function () {
    Bus::fake();

    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Running,
        'total' => 24,
        'completed' => 5,
    ]);

    $response = $this->post(route('evaluation.cancel', $run));

    $response->assertRedirect(route('evaluation.index'));
    expect($run->fresh()->status)->toBe(EvaluationRunStatus::Cancelled);

    // A cancelled run is no longer "active", so a new one can start.
    $this->post(route('evaluation.store'))->assertRedirect(route('evaluation.index'));
    expect(RagasEvaluationRun::count())->toBe(2);
});

test('cancel is a no-op for a run that already finished', function () {
    $run = RagasEvaluationRun::query()->create([
        'status' => EvaluationRunStatus::Completed,
        'total' => 24,
        'completed' => 24,
    ]);

    $this->post(route('evaluation.cancel', $run));

    expect($run->fresh()->status)->toBe(EvaluationRunStatus::Completed);
});

test('store auto-ingests a document_path dataset entry and dispatches jobs against it', function () {
    // Only fake the per-unit evaluation job — the static document's own
    // ingestion jobs (Extract/Chunk/Embed) run via dispatchSync() and must
    // actually execute so the document is real and ready before the batch
    // of RunSingleRagasEvaluationJob units gets built.
    Bus::fake([RunSingleRagasEvaluationJob::class]);
    Storage::fake('local');
    Http::fake([
        '*/embed' => fn ($request) => Http::response([
            'embeddings' => collect($request->data()['inputs'] ?? [])
                ->map(fn () => array_fill(0, 384, 0.1))
                ->all(),
        ]),
    ]);

    config(['services.ragas.dataset_path' => 'tests/Fixtures/static_document_dataset.json']);

    $response = $this->post(route('evaluation.store'));

    $response->assertRedirect(route('evaluation.index'));

    $document = Document::sole();
    expect($document->original_filename)->toBe('sample.pdf')
        ->and($document->status)->toBe(DocumentStatus::Ready);

    $run = RagasEvaluationRun::sole();
    $expectedUnits = count(RagasExperimentRunner::allConfigs());
    expect($run->total)->toBe($expectedUnits);

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === $expectedUnits
        && $batch->jobs->every(fn (RunSingleRagasEvaluationJob $job) => $job->documentId === $document->id));
});
