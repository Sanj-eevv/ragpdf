<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Retrieval\ChunkEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EmbedChunksJob implements ShouldQueue
{
    use Queueable;

    private const int BATCH_SIZE = 100;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        $this->document->chunks()
            ->whereNull('embedding')
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($chunks): void {
                $embeddings = (new ChunkEmbedder)->embed($chunks->pluck('content')->all());

                foreach ($chunks->values() as $index => $chunk) {
                    $chunk->update(['embedding' => $embeddings[$index]]);
                }
            });

        $this->document->update(['status' => DocumentStatus::Ready]);
    }

    public function failed(Throwable $exception): void
    {
        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
