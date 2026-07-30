<?php

namespace App\Console\Commands;

use App\Enums\ChunkingStrategy;
use App\Enums\DocumentStatus;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Services\Evaluation\RagasEvaluator;
use App\Services\QueryPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('rag:evaluate
    {--dataset=database/fixtures/ragas_dataset.json : Path (relative to project root) to the evaluation dataset JSON file}
    {--limit= : Only evaluate the first N questions in the dataset}
    {--configs= : Comma-separated list of config IDs to run (default: all 8). Run with an invalid value to list available IDs}')]
#[Description('Run the RAGAS evaluation matrix (chunking strategy x retrieval algorithm x rerank) against the curated question dataset.')]
class RunRagasEvaluation extends Command
{
    public function handle(
        QueryPipeline $pipeline,
        RagasEvaluator $ragasEvaluator,
    ): int {
        $questions = $this->loadDataset();

        if ($questions === null) {
            return self::FAILURE;
        }

        if ($limit = $this->option('limit')) {
            $questions = $questions->take((int) $limit);
        }

        $configs = $this->resolveConfigs();

        if ($configs === null) {
            return self::FAILURE;
        }

        $rows = [];
        $progress = $this->output->createProgressBar($questions->count() * count($configs));
        $progress->start();

        foreach ($questions as $entry) {
            $document = Document::query()
                ->where('original_filename', $entry['document_filename'])
                ->where('status', DocumentStatus::Ready)
                ->first();

            if (! $document) {
                $progress->clear();
                $this->warn("Skipping \"{$entry['question']}\" — no ready document named [{$entry['document_filename']}].");
                $progress->display();
                $progress->advance(count($configs));

                continue;
            }

            foreach ($configs as $config) {
                $rows[] = $this->runOne($entry, $document, $config, $pipeline, $ragasEvaluator);
                $progress->advance();
            }
        }

        $progress->finish();
        $this->newLine(2);

        if ($rows === []) {
            $this->warn('No questions were evaluated — nothing to report.');

            return self::SUCCESS;
        }

        $this->printSummary($rows);
        $path = $this->writeCsv($rows);
        $this->info("Raw per-question results written to: {$path}");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>|null
     */
    private function loadDataset(): ?Collection
    {
        $path = base_path($this->option('dataset'));

        if (! File::exists($path)) {
            $this->error("Dataset file not found: {$path}");

            return null;
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data)) {
            $this->error('Dataset file is not valid JSON.');

            return null;
        }

        return new Collection($data);
    }

    /**
     * @return array<int, array{id: string, strategy: ChunkingStrategy, algorithm: RetrievalAlgorithm, reranked: bool}>
     */
    private function allConfigs(): array
    {
        $configs = [];

        foreach (ChunkingStrategy::cases() as $strategy) {
            foreach (RetrievalAlgorithm::cases() as $algorithm) {
                foreach ([false, true] as $reranked) {
                    $configs[] = [
                        'id' => $this->configId($strategy, $algorithm, $reranked),
                        'strategy' => $strategy,
                        'algorithm' => $algorithm,
                        'reranked' => $reranked,
                    ];
                }
            }
        }

        return $configs;
    }

    private function configId(ChunkingStrategy $strategy, RetrievalAlgorithm $algorithm, bool $reranked): string
    {
        return "{$strategy->value}_{$algorithm->value}_".($reranked ? 'rerank' : 'no_rerank');
    }

    /**
     * @return array<int, array{id: string, strategy: ChunkingStrategy, algorithm: RetrievalAlgorithm, reranked: bool}>|null
     */
    private function resolveConfigs(): ?array
    {
        $all = $this->allConfigs();

        $filter = $this->option('configs');

        if (! $filter) {
            return $all;
        }

        $wanted = array_map('trim', explode(',', $filter));
        $matched = array_values(array_filter($all, fn (array $config) => in_array($config['id'], $wanted, true)));

        if ($matched === []) {
            $this->error('No configs matched. Available: '.implode(', ', array_column($all, 'id')));

            return null;
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{id: string, strategy: ChunkingStrategy, algorithm: RetrievalAlgorithm, reranked: bool}  $config
     * @return array<string, mixed>
     */
    private function runOne(array $entry, Document $document, array $config, QueryPipeline $pipeline, RagasEvaluator $ragasEvaluator): array
    {
        $question = (string) $entry['question'];

        ['query' => $query] = $pipeline->run(
            $question,
            $document->id,
            $config['strategy'],
            $config['algorithm'],
            $config['reranked'],
        );

        $evaluation = $ragasEvaluator->evaluate($query, $entry['ground_truth_answer'] ?? null);

        return [
            'config_id' => $config['id'],
            'chunking_strategy' => $config['strategy']->value,
            'retrieval_algorithm' => $config['algorithm']->value,
            'reranked' => $config['reranked'] ? 'yes' : 'no',
            'document' => $entry['document_filename'],
            'question' => $question,
            'answerable' => ($entry['answerable'] ?? true) ? 'yes' : 'no',
            'answer' => $query->answer,
            'context_precision' => $evaluation->context_precision,
            'context_recall' => $evaluation->context_recall,
            'faithfulness' => $evaluation->faithfulness,
            'answer_relevance' => $evaluation->answer_relevance,
            'latency_ms' => $query->latency_ms,
            'prompt_tokens' => $query->prompt_tokens,
            'completion_tokens' => $query->completion_tokens,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function printSummary(array $rows): void
    {
        $grouped = (new Collection($rows))->groupBy('config_id');

        $summaryRows = $grouped->map(function (Collection $group, string $configId) {
            $tokens = $group->map(fn (array $row) => $row['prompt_tokens'] + $row['completion_tokens']);

            return [
                $configId,
                (string) $group->count(),
                $this->formatScore($this->mean($group->pluck('context_precision'))),
                $this->formatScore($this->mean($group->pluck('context_recall'))),
                $this->formatScore($this->mean($group->pluck('faithfulness'))),
                $this->formatScore($this->mean($group->pluck('answer_relevance'))),
                $this->formatNumber($this->mean($group->pluck('latency_ms'))),
                $this->formatNumber($this->mean($tokens)),
            ];
        })->values()->all();

        $this->table(
            ['Config', 'N', 'Ctx Precision', 'Ctx Recall', 'Faithfulness', 'Answer Relevance', 'Avg Latency (ms)', 'Avg Tokens'],
            $summaryRows,
        );
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function mean(Collection $values): ?float
    {
        $filtered = $values->filter(fn ($value) => $value !== null);

        return $filtered->isEmpty() ? null : (float) $filtered->avg();
    }

    private function formatScore(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 3);
    }

    private function formatNumber(?float $value): string
    {
        return $value === null ? '—' : (string) round($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('eval');

        $relativePath = 'eval/results_'.now()->format('Y_m_d_His').'.csv';

        $handle = fopen($disk->path($relativePath), 'w');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$relativePath} for writing.");
        }

        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $disk->path($relativePath);
    }
}
