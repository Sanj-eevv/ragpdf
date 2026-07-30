<?php

namespace App\Http\Controllers;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Http\Requests\StoreQueryRequest;
use App\Services\QueryPipeline;
use Illuminate\Http\JsonResponse;

class QueryController extends Controller
{
    public function __construct(private readonly QueryPipeline $pipeline) {}

    public function store(StoreQueryRequest $request): JsonResponse
    {
        $question = $request->string('question')->toString();
        $documentId = $request->integer('document_id') ?: null;
        $strategy = ChunkingStrategy::from($request->string('chunking_strategy')->toString());
        $algorithm = RetrievalAlgorithm::from($request->string('retrieval_algorithm')->toString());
        $reranked = $request->boolean('reranked');

        ['query' => $query, 'chunks' => $chunks] = $this->pipeline->run(
            $question,
            $documentId,
            $strategy,
            $algorithm,
            $reranked,
        );

        return response()->json([
            'query' => $query,
            'context' => $chunks->map(fn ($chunk) => [
                'id' => $chunk->id,
                'content' => $chunk->content,
            ])->values(),
        ]);
    }
}
