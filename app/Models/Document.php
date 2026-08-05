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

    public function rawTextPath(): string
    {
        return "documents/{$this->id}/raw.txt";
    }

    /**
     * Directory holding this document's derived files (currently just
     * rawTextPath()), separate from the originally uploaded file at
     * disk_path.
     */
    public function directoryPath(): string
    {
        return "documents/{$this->id}";
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
