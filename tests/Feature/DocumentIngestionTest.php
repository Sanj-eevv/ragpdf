<?php

use App\Enums\ChunkingStrategy;
use App\Enums\DocumentStatus;
use App\Jobs\ChunkDocumentJob;
use App\Jobs\EmbedChunksJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Models\Document;
use App\Services\TextSplitter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

test('uploading a document creates it and dispatches the ingestion job chain in order', function () {
    Bus::fake();
    Storage::fake('local');

    $file = UploadedFile::fake()->create('paper.pdf', 100, 'application/pdf');

    $response = $this->post(route('documents.store'), ['file' => $file]);

    $response->assertRedirect(route('documents.index'));

    $document = Document::sole();
    expect($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->original_filename)->toBe('paper.pdf');

    Bus::assertChained([
        ExtractDocumentTextJob::class,
        ChunkDocumentJob::class,
        EmbedChunksJob::class,
    ]);
});

test('the full ingestion pipeline extracts, chunks under both strategies, and embeds a real pdf', function () {
    Storage::fake('local');
    Embeddings::fake();

    $diskPath = 'documents/sample.pdf';
    Storage::disk('local')->put($diskPath, file_get_contents(__DIR__.'/../Fixtures/sample.pdf'));

    $document = Document::factory()->pending()->create(['disk_path' => $diskPath]);

    (new ExtractDocumentTextJob($document))->handle();
    expect($document->fresh()->status)->toBe(DocumentStatus::Chunking);
    expect(Storage::disk('local')->get($document->rawTextPath()))->toContain('Pneumonia');

    (new ChunkDocumentJob($document))->handle(app(TextSplitter::class));
    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Embedding);

    $strategyCounts = $document->chunks()
        ->get()
        ->groupBy(fn ($chunk) => $chunk->chunking_strategy->value)
        ->map->count();

    expect($strategyCounts->keys()->sort()->values()->all())->toBe([
        ChunkingStrategy::Tokens1000->value,
        ChunkingStrategy::Tokens500->value,
    ])
        ->and($document->chunks()->whereNull('embedding')->count())->toBe($document->chunks()->count());

    (new EmbedChunksJob($document))->handle();
    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks()->whereNull('embedding')->count())->toBe(0);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Pneumonia'));
});

test('uploading a non-PDF file is rejected by validation', function () {
    Bus::fake();
    Storage::fake('local');

    $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

    $response = $this->post(route('documents.store'), ['file' => $file]);

    $response->assertSessionHasErrors('file');
    expect(Document::count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('uploading without a file is rejected by validation', function () {
    $response = $this->post(route('documents.store'), []);

    $response->assertSessionHasErrors('file');
});

test('extraction failure marks the document as failed with an error message', function () {
    Storage::fake('local');

    $document = Document::factory()->pending()->create(['disk_path' => 'documents/does-not-exist.pdf']);

    $job = new ExtractDocumentTextJob($document);

    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error_message)->not->toBeNull();
});
