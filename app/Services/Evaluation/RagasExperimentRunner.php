<?php

namespace App\Services\Evaluation;

use App\Enums\ChunkingStrategy;
use App\Enums\DocumentStatus;
use App\Enums\EvaluationRunStatus;
use App\Enums\RetrievalAlgorithm;
use App\Jobs\ChunkDocumentJob;
use App\Jobs\EmbedChunksJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Models\Document;
use App\Models\Query;
use App\Models\RagasEvaluationRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dataset/config helpers for the RAGAS experiment matrix (chunking strategy
 * x retrieval algorithm x rerank), shared by the GUI-triggered background
 * run (RagasEvaluationController::store(), RunSingleRagasEvaluationJob):
 * loading the curated question dataset, enumerating the 8 configs, and
 * aggregating/exporting results once a run's jobs have all finished.
 */
class RagasExperimentRunner
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function loadDataset(string $relativePath): Collection
    {
        $path = base_path($relativePath);

        if (! File::exists($path)) {
            throw new RuntimeException("Dataset file not found: {$path}");
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data)) {
            throw new RuntimeException('Dataset file is not valid JSON.');
        }

        return new Collection($data);
    }

    /**
     * @return array<int, array{id: string, strategy: ChunkingStrategy, algorithm: RetrievalAlgorithm, reranked: bool}>
     */
    public static function allConfigs(): array
    {
        $configs = [];

        foreach (ChunkingStrategy::cases() as $strategy) {
            foreach (RetrievalAlgorithm::cases() as $algorithm) {
                foreach ([false, true] as $reranked) {
                    $configs[] = [
                        'id' => self::configId($strategy, $algorithm, $reranked),
                        'strategy' => $strategy,
                        'algorithm' => $algorithm,
                        'reranked' => $reranked,
                    ];
                }
            }
        }

        return $configs;
    }

    public static function configId(ChunkingStrategy $strategy, RetrievalAlgorithm $algorithm, bool $reranked): string
    {
        return "{$strategy->value}_{$algorithm->value}_".($reranked ? 'rerank' : 'no_rerank');
    }

    /**
     * Resolves the Document a dataset entry's question should run against.
     * An entry references either an already-ingested Document by
     * `document_filename` (must exist with status: ready, the normal path
     * for anything uploaded through the Documents page), or a static file
     * directly via `document_path` (relative to the project root) — that
     * file is auto-ingested (extracted, chunked under both strategies,
     * embedded) the first time it's used, without ever going through the
     * Documents page. Subsequent runs reuse the same ingested Document.
     *
     * @param  array<string, mixed>  $entry
     */
    public function resolveDocument(array $entry): ?Document
    {
        if (isset($entry['document_path'])) {
            return $this->ensureStaticDocument($entry['document_path']);
        }

        return Document::query()
            ->where('original_filename', $entry['document_filename'])
            ->where('status', DocumentStatus::Ready)
            ->first();
    }

    private function ensureStaticDocument(string $relativePath): Document
    {
        $filename = basename($relativePath);

        $existing = Document::query()
            ->where('original_filename', $filename)
            ->where('status', DocumentStatus::Ready)
            ->first();

        if ($existing) {
            return $existing;
        }

        $sourcePath = base_path($relativePath);

        if (! File::exists($sourcePath)) {
            throw new RuntimeException("Static evaluation document not found: {$sourcePath}");
        }

        $diskPath = 'documents/'.$filename;
        Storage::disk('local')->put($diskPath, File::get($sourcePath));

        $document = Document::query()->create([
            'title' => $filename,
            'original_filename' => $filename,
            'disk_path' => $diskPath,
            'status' => DocumentStatus::Pending,
        ]);

        ExtractDocumentTextJob::dispatchSync($document);
        ChunkDocumentJob::dispatchSync($document);
        EmbedChunksJob::dispatchSync($document);

        return $document->fresh();
    }

    /**
     * Aggregates raw per-question rows into one row per config, keeping
     * values as raw numbers (not formatted strings) so callers can format
     * for a console table or feed them straight into a chart.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function summarize(array $rows): array
    {
        $grouped = (new Collection($rows))->groupBy('config_id');

        return $grouped->map(function (Collection $group, string $configId) {
            $tokens = $group->map(fn (array $row) => $row['prompt_tokens'] + $row['completion_tokens']);

            return [
                'config_id' => $configId,
                'n' => $group->count(),
                'context_precision' => $this->mean($group->pluck('context_precision')),
                'context_recall' => $this->mean($group->pluck('context_recall')),
                'faithfulness' => $this->mean($group->pluck('faithfulness')),
                'answer_relevance' => $this->mean($group->pluck('answer_relevance')),
                'avg_latency_ms' => $this->mean($group->pluck('latency_ms')),
                'avg_tokens' => $this->mean($tokens),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function mean(Collection $values): ?float
    {
        $filtered = $values->filter(fn ($value) => $value !== null);

        return $filtered->isEmpty() ? null : (float) $filtered->avg();
    }

    /**
     * Recomputes and persists a run's aggregate summary, CSV, and
     * outstanding-unit count from whatever Query/RagasEvaluation rows
     * currently exist for it. Safe to call more than once for the same run
     * — once when the original batch of jobs finishes, and again any time a
     * previously-failed unit is retried (e.g. from the Horizon dashboard)
     * and later succeeds, so the run's displayed results stay accurate
     * without needing to re-run the whole evaluation. `completed` and the
     * "missing" count are derived from the real rows rather than a counter,
     * so a retry can never double-count or leave stale numbers behind.
     */
    public function refreshResults(RagasEvaluationRun $run): void
    {
        $rows = Query::query()
            ->where('ragas_evaluation_run_id', $run->id)
            ->with(['ragasEvaluation', 'document'])
            ->get()
            ->map(fn (Query $query) => $this->buildRow($query))
            ->all();

        $completed = count($rows);
        $missing = $run->total - $completed;

        $run->update([
            'status' => EvaluationRunStatus::Completed,
            'completed' => $completed,
            'summary' => $rows === [] ? [] : $this->summarize($rows),
            'csv_path' => $rows === [] ? null : $this->writeCsv($rows),
            'error_message' => $missing > 0
                ? "{$missing} of {$run->total} question-configs are missing — check the Horizon failed jobs dashboard and retry them; this run's results will refresh automatically."
                : null,
        ]);
    }

    /**
     * Groups a run's results by question, one entry per question with every
     * config's own answer and scores — for the GUI's per-question view,
     * where the aggregate summary()/writeCsv() output averages across
     * questions and hides exactly the kind of per-question detail (e.g. did
     * it correctly answer "Information Not Found"?) that matters most.
     * Computed fresh from the Query/RagasEvaluation rows on each page load
     * rather than persisted, same reasoning as refreshResults().
     *
     * @return array<int, array{question: string, configs: array<int, array<string, mixed>>}>
     */
    public function detailsByQuestion(RagasEvaluationRun $run): array
    {
        return Query::query()
            ->where('ragas_evaluation_run_id', $run->id)
            ->with('ragasEvaluation')
            ->orderBy('id')
            ->get()
            ->map(fn (Query $query) => $this->buildRow($query))
            ->groupBy('question')
            ->map(fn (Collection $rows, string $question) => [
                'question' => $question,
                'ground_truth_answer' => $rows->first()['ground_truth_answer'],
                'configs' => $rows->map(fn (array $row) => [
                    'config_id' => $row['config_id'],
                    'answer' => $row['answer'],
                    'context_precision' => $row['context_precision'],
                    'context_recall' => $row['context_recall'],
                    'faithfulness' => $row['faithfulness'],
                    'answer_relevance' => $row['answer_relevance'],
                    'latency_ms' => $row['latency_ms'],
                    'prompt_tokens' => $row['prompt_tokens'],
                    'completion_tokens' => $row['completion_tokens'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Batch callbacks are serialized and run later by the queue, so this
     * takes a run ID rather than a captured model instance (per Laravel's
     * own warning about batch closures) and re-fetches the run fresh rather
     * than trusting anything captured in the closure.
     */
    public function finalizeRun(int $runId): void
    {
        $run = RagasEvaluationRun::query()->find($runId);

        if (! $run || $run->status === EvaluationRunStatus::Cancelled) {
            return;
        }

        $this->refreshResults($run);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(Query $query): array
    {
        $evaluation = $query->ragasEvaluation;

        return [
            'config_id' => self::configId(
                $query->chunking_strategy,
                $query->retrieval_algorithm,
                $query->reranked,
            ),
            'chunking_strategy' => $query->chunking_strategy->value,
            'retrieval_algorithm' => $query->retrieval_algorithm->value,
            'reranked' => $query->reranked ? 'yes' : 'no',
            'document' => $query->document?->original_filename,
            'question' => $query->question,
            'answer' => $query->answer,
            'ground_truth_answer' => $query->ground_truth_answer,
            'context_precision' => $evaluation?->context_precision,
            'context_recall' => $evaluation?->context_recall,
            'faithfulness' => $evaluation?->faithfulness,
            'answer_relevance' => $evaluation?->answer_relevance,
            'latency_ms' => $query->latency_ms,
            'prompt_tokens' => $query->prompt_tokens,
            'completion_tokens' => $query->completion_tokens,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return string relative path on the `local` disk
     */
    public function writeCsv(array $rows): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('eval');

        $relativePath = 'eval/results_'.now()->format('Y_m_d_His').'.csv';

        $handle = fopen($disk->path($relativePath), 'w');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$relativePath} for writing.");
        }

        fputcsv($handle, array_keys($rows[0]), escape: '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '\\');
        }

        fclose($handle);

        return $relativePath;
    }
}
