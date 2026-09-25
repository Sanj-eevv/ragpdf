<?php

namespace App\Http\Controllers;

use App\Enums\EvaluationRunStatus;
use App\Jobs\BuildRagasEvaluationRunJob;
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
    public function index(RagasExperimentRunner $runner): Response
    {
        try {
            $questions = $runner->loadDataset(config('services.ragas.dataset_path'))->values()->all();
        } catch (RuntimeException) {
            $questions = [];
        }

        $run = RagasEvaluationRun::query()->latest()->first();

        return Inertia::render('Evaluation/Index', [
            'run' => $run,
            'questions' => $questions,
            'details' => $run && $run->status === EvaluationRunStatus::Completed
                ? $runner->detailsByQuestion($run)
                : [],
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

        $run = RagasEvaluationRun::query()->create([
            'status' => EvaluationRunStatus::Queued,
        ]);

        // Resolving each entry's Document (and dispatching the per-unit
        // evaluation batch once they're ready) happens in a queued job, not
        // here — resolveDocument() can trigger a full extract/chunk/embed
        // ingestion for dataset entries seen for the first time, which is
        // too slow to run inside this request.
        BuildRagasEvaluationRunJob::dispatch($run, $questions->all());

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
}
