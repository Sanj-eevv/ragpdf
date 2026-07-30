<?php

namespace App\Jobs;

use App\Enums\ChunkingStrategy;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\TextSplitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ChunkDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document) {}

    public function handle(TextSplitter $splitter): void
    {
        $text = Storage::disk('local')->get($this->document->rawTextPath());

        foreach (ChunkingStrategy::cases() as $strategy) {
            $chunks = $splitter->split((string) $text, $strategy);

            foreach ($chunks as $index => $content) {
                $this->document->chunks()->create([
                    'chunking_strategy' => $strategy,
                    'chunk_index' => $index,
                    'content' => $content,
                    'token_count' => $splitter->tokenCount($content),
                ]);
            }
        }

        $this->document->update(['status' => DocumentStatus::Embedding]);
    }

    public function failed(Throwable $exception): void
    {
        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
