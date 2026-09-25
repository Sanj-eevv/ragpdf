<?php

use App\Enums\DocumentStatus;
use App\Enums\EvaluationRunStatus;
use App\Jobs\BuildRagasEvaluationRunJob;
use App\Jobs\RunSingleRagasEvaluationJob;
use App\Models\Document;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasExperimentRunner;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;

test('marks the run failed when a dataset entry references a missing static document', function () {
    Bus::fake([RunSingleRagasEvaluationJob::class]);

    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Queued]);

    (new BuildRagasEvaluationRunJob($run, [
        ['document_path' => 'tests/Fixtures/does-not-exist.pdf', 'question' => 'q'],
    ]))->handle(app(RagasExperimentRunner::class));

    expect($run->fresh()->status)->toBe(EvaluationRunStatus::Failed)
        ->and($run->fresh()->error_message)->toContain('does-not-exist.pdf');

    Bus::assertNothingBatched();
});

test('does nothing when the run was cancelled before the job ran', function () {
    Bus::fake([RunSingleRagasEvaluationJob::class]);

    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Cancelled]);

    Document::factory()->create(['original_filename' => 'test-doc.pdf', 'status' => DocumentStatus::Ready]);

    (new BuildRagasEvaluationRunJob($run, [
        ['document_filename' => 'test-doc.pdf', 'question' => 'q'],
    ]))->handle(app(RagasExperimentRunner::class));

    expect($run->fresh()->status)->toBe(EvaluationRunStatus::Cancelled)
        ->and($run->fresh()->total)->toBe(0);

    Bus::assertNothingBatched();
});

test('builds and dispatches one batched job per question x config unit once documents resolve', function () {
    Bus::fake([RunSingleRagasEvaluationJob::class]);

    $document = Document::factory()->create(['original_filename' => 'test-doc.pdf', 'status' => DocumentStatus::Ready]);

    $run = RagasEvaluationRun::query()->create(['status' => EvaluationRunStatus::Queued]);

    (new BuildRagasEvaluationRunJob($run, [
        ['document_filename' => 'test-doc.pdf', 'question' => 'q1', 'ground_truth_answer' => 'a1'],
        ['document_filename' => 'missing-doc.pdf', 'question' => 'q2', 'ground_truth_answer' => 'a2'],
    ]))->handle(app(RagasExperimentRunner::class));

    $expectedUnits = count(RagasExperimentRunner::allConfigs());

    expect($run->fresh()->status)->toBe(EvaluationRunStatus::Running)
        ->and($run->fresh()->total)->toBe($expectedUnits)
        ->and($run->fresh()->batch_id)->not->toBeNull();

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === $expectedUnits
        && $batch->jobs->every(fn (RunSingleRagasEvaluationJob $job) => $job->documentId === $document->id));
});
