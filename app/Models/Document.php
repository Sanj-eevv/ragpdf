<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'original_filename',
        'disk_path',
        'page_count',
        'status',
        'error_message',
    ];

    /**
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Path (on the `local` disk) where this document's extracted plain text is stored.
     */
    public function rawTextPath(): string
    {
        return "documents/{$this->id}/raw.txt";
    }

    /**
     * @return HasMany<Query, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
        ];
    }
}
