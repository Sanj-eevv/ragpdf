<?php

namespace App\Services\Retrieval;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;

class RetrievalService
{
    public function __construct(
        private readonly DenseRetriever $denseRetriever,
        private readonly HybridRetriever $hybridRetriever,
    ) {}

    /**
     * @return Collection<int, DocumentChunk>
     */
    public function search(
        RetrievalAlgorithm $algorithm,
        string $question,
        ChunkingStrategy $strategy,
        ?int $documentId,
        int $limit = 15,
    ): Collection {
        return match ($algorithm) {
            RetrievalAlgorithm::Dense => $this->denseRetriever->search($question, $strategy, $documentId, $limit),
            RetrievalAlgorithm::Hybrid => $this->hybridRetriever->search($question, $strategy, $documentId, $limit),
        };
    }
}
