<?php

namespace App\Services\Retrieval;

use App\Enums\ChunkingStrategy;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;

/**
 * Algorithm 1 — Pure Dense Vector Retrieval: cosine similarity search over
 * pgvector embeddings via Laravel's `whereVectorSimilarTo`, which generates
 * the query embedding automatically via the configured AI SDK provider.
 */
class DenseRetriever
{
    /**
     * The thesis's Algorithm 1 is an unthresholded top-K rank
     * (`ORDER BY embedding <=> query LIMIT 15`, no relevance cutoff) —
     * Laravel's `whereVectorSimilarTo` defaults to `minSimilarity: 0.6`,
     * so it's overridden here to admit every result and match the thesis
     * exactly (including its "irrelevant noise" weakness that Phase 4's
     * re-ranking step exists to correct).
     */
    private const float NO_SIMILARITY_THRESHOLD = -1.0;

    /**
     * @return Collection<int, DocumentChunk>
     */
    public function search(string $question, ChunkingStrategy $strategy, ?int $documentId, int $limit = 15): Collection
    {
        return DocumentChunk::query()
            ->where('chunking_strategy', $strategy)
            ->when($documentId, fn ($query) => $query->where('document_id', $documentId))
            ->whereVectorSimilarTo('embedding', $question, minSimilarity: self::NO_SIMILARITY_THRESHOLD)
            ->limit($limit)
            ->get();
    }
}
