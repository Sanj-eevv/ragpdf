<?php

namespace App\Services;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Models\DocumentChunk;
use App\Models\Query;
use App\Services\Retrieval\ChunkReranker;
use App\Services\Retrieval\RetrievalService;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shared retrieval -> (optional) rerank -> generate -> persist pipeline
 * used both by the interactive chat endpoint (QueryController) and the
 * offline experiment runner (rag:evaluate), so the two stay in lockstep.
 */
class QueryPipeline
{
    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly ChunkReranker $reranker,
        private readonly AnswerGenerator $answerGenerator,
    ) {}

    /**
     * @return array{query: Query, chunks: Collection<int, DocumentChunk>}
     */
    public function run(
        string $question,
        ?int $documentId,
        ChunkingStrategy $strategy,
        RetrievalAlgorithm $algorithm,
        bool $reranked,
    ): array {
        $chunks = $this->retrievalService->search($algorithm, $question, $strategy, $documentId);

        if ($reranked) {
            $chunks = $this->reranker->rerank($chunks, $question);
        }

        $result = $this->answerGenerator->generate($question, $chunks);

        $query = Query::query()->create([
            'document_id' => $documentId,
            'question' => $question,
            'answer' => $result->answer,
            'chunking_strategy' => $strategy,
            'retrieval_algorithm' => $algorithm,
            'reranked' => $reranked,
            'retrieved_chunk_ids' => $chunks->pluck('id')->all(),
            'latency_ms' => $result->latencyMs,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
        ]);

        return ['query' => $query, 'chunks' => $chunks];
    }
}
