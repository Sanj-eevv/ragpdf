<?php

namespace App\Jobs;

use App\Enums\EvaluationRunStatus;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasExperimentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

/**
 * Resolves each dataset entry's Document and dispatches the per-unit
 * evaluation batch, dispatched by RagasEvaluationController@store rather
 * than run inline in the HTTP request — resolveDocument() can trigger a
 * full extract/chunk/embed ingestion (via
 * RagasExperimentRunner::ensureStaticDocument()) for `document_path` dataset
 * entries the first time they're used, which is too slow to run inside a
 * web request.
 */
class BuildRagasEvaluationRunJob implements ShouldQueue
{
    use Queueable;

    private const int UNIT_DISPATCH_DELAY_SECONDS = 5;

    /**
     * @param  array<int, array<string, mixed>>  $questions
     */
    public function __construct(
        public RagasEvaluationRun $run,
        public array $questions,
    ) {}

    public function handle(RagasExperimentRunner $runner): void
    {
        $run = $this->run->fresh();

        if ($run === null || $run->status === EvaluationRunStatus::Cancelled) {
            return;
        }

        $configs = RagasExperimentRunner::allConfigs();

        $units = [];

        foreach ($this->questions as $entry) {
            try {
                $document = $runner->resolveDocument($entry);
            } catch (RuntimeException $e) {
                $run->update([
                    'status' => EvaluationRunStatus::Failed,
                    'error_message' => $e->getMessage(),
                ]);

                return;
            }

            if (! $document) {
                continue;
            }

            foreach ($configs as $config) {
                $units[] = [$document, $entry, $config];
            }
        }

        if ($units === []) {
            $run->update([
                'status' => EvaluationRunStatus::Completed,
                'total' => 0,
                'summary' => [],
            ]);

            return;
        }

        // Staggered by index so units don't all hit the embedding/rerank
        // sidecar and the Gemini API at the same instant — each unit becomes
        // available 5s after the previous one rather than all at once.
        $jobs = array_map(fn (array $unit, int $index) => (new RunSingleRagasEvaluationJob(
            $run,
            $unit[0]->id,
            (string) $unit[1]['question'],
            $unit[1]['ground_truth_answer'] ?? null,
            $unit[2]['strategy'],
            $unit[2]['algorithm'],
            $unit[2]['reranked'],
        ))->delay(now()->addSeconds($index * self::UNIT_DISPATCH_DELAY_SECONDS)), $units, array_keys($units));

        $runId = $run->id;

        // One job per question x config unit (not one job for the whole
        // matrix) — so a 50-question x 8-config run can't blow Horizon's
        // 60s job timeout, workers can process units in parallel, and
        // allowFailures() means one bad unit doesn't cancel the rest (by
        // default Laravel cancels the whole batch on the first failure).
        $batch = Bus::batch($jobs)
            ->allowFailures()
            ->name("ragas-evaluation-run-{$runId}")
            ->finally(function () use ($runId) {
                app(RagasExperimentRunner::class)->finalizeRun($runId);
            })
            ->dispatch();

        $run->update([
            'status' => EvaluationRunStatus::Running,
            'total' => count($units),
            'batch_id' => $batch->id,
        ]);
    }
}
