<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToText\Pdf;
use Throwable;

class ExtractDocumentTextJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        $text = Pdf::getText(Storage::disk('local')->path($this->document->disk_path));

        Storage::disk('local')->put($this->document->rawTextPath(), $text);

        $this->document->update(['status' => DocumentStatus::Chunking]);
    }

    public function failed(Throwable $exception): void
    {
        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
