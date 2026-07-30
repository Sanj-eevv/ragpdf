<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Embeddings;
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
                $response = Embeddings::for($chunks->pluck('content')->all())->generate();

                foreach ($chunks->values() as $index => $chunk) {
                    $chunk->update(['embedding' => $response->embeddings[$index]]);
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
