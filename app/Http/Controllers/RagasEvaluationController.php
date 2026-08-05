<?php

namespace App\Http\Controllers;

use App\Enums\EvaluationRunStatus;
use App\Jobs\RunSingleRagasEvaluationJob;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasExperimentRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RagasEvaluationController extends Controller
{
    private const int UNIT_DISPATCH_DELAY_SECONDS = 5;

    public function index(RagasExperimentRunner $runner): Response
    {
        try {
            $questions = $runner->loadDataset(config('services.ragas.dataset_path'))->values()->all();
        } catch (RuntimeException) {
            $questions = [];
        }

        return Inertia::render('Evaluation/Index', [
            'run' => RagasEvaluationRun::query()->latest()->first(),
            'questions' => $questions,
        ]);
    }

    public function store(RagasExperimentRunner $runner): RedirectResponse
    {
        $alreadyActive = RagasEvaluationRun::query()
            ->whereIn('status', [EvaluationRunStatus::Queued, EvaluationRunStatus::Running])
            ->exists();

        if ($alreadyActive) {
            return back()->withErrors(['evaluation' => 'An evaluation run is already in progress.']);
        }

        try {
            $questions = $runner->loadDataset(config('services.ragas.dataset_path'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['evaluation' => $e->getMessage()]);
        }

        $configs = RagasExperimentRunner::allConfigs();

        $units = [];

        foreach ($questions as $entry) {
            try {
                $document = $runner->resolveDocument($entry);
            } catch (RuntimeException $e) {
                return back()->withErrors(['evaluation' => $e->getMessage()]);
            }

            if (! $document) {
                continue;
            }

            foreach ($configs as $config) {
                $units[] = [$document, $entry, $config];
            }
        }

        if ($units === []) {
            RagasEvaluationRun::query()->create([
                'status' => EvaluationRunStatus::Completed,
                'total' => 0,
                'summary' => [],
            ]);

            return to_route('evaluation.index');
        }

        $run = RagasEvaluationRun::query()->create([
            'status' => EvaluationRunStatus::Queued,
            'total' => count($units),
        ]);

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
                self::finalize($runId);
            })
            ->dispatch();

        $run->update(['status' => EvaluationRunStatus::Running, 'batch_id' => $batch->id]);

        return to_route('evaluation.index');
    }

    public function download(RagasEvaluationRun $run): StreamedResponse
    {
        abort_unless($run->csv_path !== null, 404);

        return Storage::disk('local')->download($run->csv_path);
    }

    public function cancel(RagasEvaluationRun $run): RedirectResponse
    {
        if (in_array($run->status, [EvaluationRunStatus::Queued, EvaluationRunStatus::Running], true)) {
            if ($run->batch_id) {
                Bus::findBatch($run->batch_id)?->cancel();
            }

            $run->update(['status' => EvaluationRunStatus::Cancelled]);
        }

        return to_route('evaluation.index');
    }

    /**
     * Batch callbacks are serialized and run later by the queue, so this is
     * a static method rather than relying on `$this` (per Laravel's own
     * warning about batch closures) and re-fetches everything fresh by ID
     * rather than capturing model instances into the closure.
     */
    private static function finalize(int $runId): void
    {
        $run = RagasEvaluationRun::query()->find($runId);

        if (! $run || $run->status === EvaluationRunStatus::Cancelled) {
            return;
        }

        app(RagasExperimentRunner::class)->refreshResults($run);
    }
}
