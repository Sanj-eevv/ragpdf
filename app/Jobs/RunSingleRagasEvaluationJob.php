<?php

namespace App\Jobs;

use App\Enums\ChunkingStrategy;
use App\Enums\EvaluationRunStatus;
use App\Enums\RetrievalAlgorithm;
use App\Models\Query;
use App\Models\RagasEvaluationRun;
use App\Services\Evaluation\RagasEvaluator;
use App\Services\Evaluation\RagasExperimentRunner;
use App\Services\QueryPipeline;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Evaluates exactly one question x config combination, dispatched as part of
 * a Bus::batch() by RagasEvaluationController@store — one job per unit
 * rather than one job for the whole matrix, so a 50-question x 8-config run
 * doesn't blow Horizon's 60s job timeout, workers can run units in parallel,
 * and one failing question/config doesn't take the rest of the run down
 * with it (see the batch's allowFailures() call at the dispatch site).
 */
class RunSingleRagasEvaluationJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * One bad unit shouldn't retry and burn API budget — it's already
     * tolerated at the batch level via allowFailures().
     */
    public int $tries = 1;

    public function __construct(
        public RagasEvaluationRun $run,
        public int $documentId,
        public string $question,
        public ?string $groundTruthAnswer,
        public ChunkingStrategy $chunkingStrategy,
        public RetrievalAlgorithm $retrievalAlgorithm,
        public bool $reranked,
    ) {}

    public function handle(QueryPipeline $pipeline, RagasEvaluator $ragasEvaluator, RagasExperimentRunner $runner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $query = $this->findExistingQuery();

        if ($query?->ragasEvaluation !== null) {
            // A previous attempt (this same job, retried from Horizon after
            // a later step failed) already fully finished this exact unit —
            // re-running it would just create a duplicate Query row and burn
            // API budget for nothing.
            return;
        }

        if ($query === null) {
            // No previous attempt got far enough to create a Query at all —
            // this is either the first try, or every earlier try failed
            // before the pipeline finished. Run it fresh.
            ['query' => $query] = $pipeline->run(
                $this->question,
                $this->documentId,
                $this->chunkingStrategy,
                $this->retrievalAlgorithm,
                $this->reranked,
            );

            $query->update([
                'ragas_evaluation_run_id' => $this->run->id,
                'ground_truth_answer' => $this->groundTruthAnswer,
            ]);
        }
        // else: the pipeline already produced an answer last time and only
        // judging failed — reuse that Query rather than generating a new
        // (potentially different) answer for the same unit.

        $ragasEvaluator->evaluate($query, $this->groundTruthAnswer);

        $this->recordProgress();

        $run = $this->run->fresh();

        if ($run !== null && $run->status === EvaluationRunStatus::Completed) {
            // This unit succeeded after its run had already finished — e.g. a
            // Horizon-triggered retry of a previously failed job — so the
            // run's summary/CSV/missing-count were computed without it.
            // Refresh them now so the results stay accurate.
            $runner->refreshResults($run);
        }
    }

    /**
     * Finds the Query already recorded for this exact unit (run + question +
     * config), if any — e.g. from an earlier attempt of this same job that
     * got retried after failing downstream of the pipeline.
     */
    private function findExistingQuery(): ?Query
    {
        return Query::query()
            ->where('ragas_evaluation_run_id', $this->run->id)
            ->where('question', $this->question)
            ->where('chunking_strategy', $this->chunkingStrategy)
            ->where('retrieval_algorithm', $this->retrievalAlgorithm)
            ->where('reranked', $this->reranked)
            ->first();
    }

    /**
     * Deliberately does not touch `completed` — only a genuinely successful
     * attempt should count as done, so a later Horizon retry that succeeds
     * can't double-count against the one this replaces.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    private function recordProgress(): void
    {
        DB::table('ragas_evaluation_runs')->where('id', $this->run->id)->increment('completed');
    }
}
