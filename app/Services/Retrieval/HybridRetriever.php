<?php

namespace App\Services\Retrieval;

use App\Enums\ChunkingStrategy;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;

/**
 * Algorithm 2 — Hybrid Search with Reciprocal Rank Fusion: runs the dense
 * vector search (Algorithm 1) alongside a PostgreSQL full-text search, then
 * fuses the two ranked lists using RRF rather than combining raw scores
 * (cosine similarity and ts_rank are not on comparable scales).
 */
class HybridRetriever
{
    public function __construct(
        private readonly DenseRetriever $denseRetriever,
        private readonly ReciprocalRankFusion $fusion,
    ) {}

    /**
     * @return Collection<int, DocumentChunk>
     */
    public function search(string $question, ChunkingStrategy $strategy, ?int $documentId, int $limit = 15): Collection
    {
        $denseResults = $this->denseRetriever->search($question, $strategy, $documentId, $limit);
        $sparseResults = $this->lexicalSearch($question, $strategy, $documentId, $limit);

        $chunksById = [];
        foreach ($denseResults->merge($sparseResults) as $chunk) {
            $chunksById[$chunk->id] = $chunk;
        }

        $fusedIds = $this->fusion->fuse([
            $denseResults->pluck('id')->all(),
            $sparseResults->pluck('id')->all(),
        ], $limit);

        return new Collection(array_map(fn (int $chunkId) => $chunksById[$chunkId], $fusedIds));
    }

    /**
     * plainto_tsquery ANDs together every significant word in the question,
     * so it requires one chunk to contain every word of a full
     * natural-language question at once — that almost never happens, which
     * silently collapsed this leg to zero results (and therefore collapsed
     * hybrid search to exactly dense search, since RRF over dense + an empty
     * list just reproduces the dense ranking). Rebuilding it as an OR query
     * — any significant word matching is enough to be a lexical candidate —
     * keeps plainto_tsquery's stemming/stopword handling but fixes the
     * all-or-nothing matching.
     *
     * @return Collection<int, DocumentChunk>
     */
    private function lexicalSearch(string $question, ChunkingStrategy $strategy, ?int $documentId, int $limit): Collection
    {
        $tsQuery = "regexp_replace(plainto_tsquery('english', ?)::text, ' & ', ' | ', 'g')::tsquery";

        return DocumentChunk::query()
            ->where('chunking_strategy', $strategy)
            ->when($documentId, fn ($query) => $query->where('document_id', $documentId))
            ->whereRaw("to_tsvector('english', content) @@ {$tsQuery}", [$question])
            ->orderByRaw("ts_rank(to_tsvector('english', content), {$tsQuery}) DESC", [$question])
            ->limit($limit)
            ->get();
    }
}
