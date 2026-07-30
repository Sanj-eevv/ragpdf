<?php

namespace App\Http\Controllers;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Http\Requests\StoreQueryRequest;
use App\Models\Query;
use App\Services\AnswerGenerator;
use App\Services\Retrieval\ChunkReranker;
use App\Services\Retrieval\RetrievalService;
use Illuminate\Http\JsonResponse;

class QueryController extends Controller
{
    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly ChunkReranker $reranker,
        private readonly AnswerGenerator $answerGenerator,
    ) {}

    public function store(StoreQueryRequest $request): JsonResponse
    {
        $question = $request->string('question')->toString();
        $documentId = $request->integer('document_id') ?: null;
        $strategy = ChunkingStrategy::from($request->string('chunking_strategy')->toString());
        $algorithm = RetrievalAlgorithm::from($request->string('retrieval_algorithm')->toString());
        $reranked = $request->boolean('reranked');

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

        return response()->json([
            'query' => $query,
            'context' => $chunks->map(fn ($chunk) => [
                'id' => $chunk->id,
                'content' => $chunk->content,
            ])->values(),
        ]);
    }
}
