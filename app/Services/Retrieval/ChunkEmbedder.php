<?php

namespace App\Services\Retrieval;

use Illuminate\Support\Facades\Http;

/**
 * Generates embeddings via a self-hosted `all-MiniLM-L6-v2` model (384
 * dimensions), served by the `rerank` sidecar's `/embed` route (see
 * rerank/main.py) — the same sidecar used for reranking, so this incurs no
 * external API cost and needs no API key.
 */
class ChunkEmbedder
{
    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        return Http::baseUrl(config('services.rerank.url'))
            ->post('/embed', ['inputs' => $inputs])
            ->throw()
            ->json('embeddings');
    }
}
