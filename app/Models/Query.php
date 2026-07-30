<?php

namespace App\Models;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use Database\Factories\QueryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Query extends Model
{
    /** @use HasFactory<QueryFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'question',
        'answer',
        'chunking_strategy',
        'retrieval_algorithm',
        'reranked',
        'retrieved_chunk_ids',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
    ];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasOne<RagasEvaluation, $this>
     */
    public function ragasEvaluation(): HasOne
    {
        return $this->hasOne(RagasEvaluation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunking_strategy' => ChunkingStrategy::class,
            'retrieval_algorithm' => RetrievalAlgorithm::class,
            'reranked' => 'boolean',
            'retrieved_chunk_ids' => 'array',
        ];
    }
}
