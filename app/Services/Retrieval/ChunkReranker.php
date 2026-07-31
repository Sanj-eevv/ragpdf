<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Post-retrieval refinement: re-ranks a Top-K set of chunks by relevance
 * using a self-hosted cross-encoder model (cross-encoder/ms-marco-MiniLM-L-6-v2,
 * served by the `rerank` sidecar — see rerank/main.py), then truncates to a
 * smaller final K before the chunks are handed to the generator. Self-hosted
 * rather than a hosted reranking API, so this incurs no external API cost
 * and needs no API key.
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

        $results = Http::baseUrl(config('services.rerank.url'))
            ->post('/rerank', [
                'query' => $question,
                'documents' => array_map(fn (DocumentChunk $chunk) => $chunk->content, $ordered),
                'limit' => $limit,
            ])
            ->throw()
            ->json();

        return new Collection(array_map(
            fn (array $result) => $ordered[$result['index']],
            $results,
        ));
    }
}
