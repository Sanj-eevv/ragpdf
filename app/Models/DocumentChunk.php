<?php

namespace App\Models;

use App\Enums\ChunkingStrategy;
use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'chunking_strategy',
        'chunk_index',
        'content',
        'token_count',
        'embedding',
    ];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunking_strategy' => ChunkingStrategy::class,
            'embedding' => 'array',
        ];
    }
}
