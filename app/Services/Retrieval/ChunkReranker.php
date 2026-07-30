<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Reranking;

/**
 * Post-retrieval refinement: re-ranks a Top-K set of chunks by relevance
 * using the Laravel AI SDK's Reranking class (Jina by default, configured
 * via `default_for_reranking` in config/ai.php), then truncates to a
 * smaller final K before the chunks are handed to the generator.
 */
class ChunkReranker
{
    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     * @return Collection<int, DocumentChunk>
     */
    public function rerank(Collection $chunks, string $question, int $limit = 5): Collection
    {
        if ($chunks->isEmpty()) {
            return $chunks;
        }

        $ordered = $chunks->values()->all();

        $response = Reranking::of(array_map(fn (DocumentChunk $chunk) => $chunk->content, $ordered))
            ->limit($limit)
            ->rerank($question);

        return new Collection(array_map(
            fn ($result) => $ordered[$result->index],
            $response->results,
        ));
    }
}
