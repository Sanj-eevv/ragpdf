<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Query;
use Illuminate\Support\Facades\Storage;

test('deleting a document removes it, its chunks, its files, and unlinks its queries', function () {
    Storage::fake('local');

    $document = Document::factory()->create(['disk_path' => 'documents/paper.pdf']);
    Storage::disk('local')->put($document->disk_path, 'pdf bytes');
    Storage::disk('local')->put($document->rawTextPath(), 'extracted text');

    $chunk = DocumentChunk::factory()->for($document)->create();
    $query = Query::factory()->for($document)->create();

    $response = $this->delete(route('documents.destroy', $document));

    $response->assertRedirect(route('documents.index'));

    expect(Document::find($document->id))->toBeNull()
        ->and(DocumentChunk::find($chunk->id))->toBeNull()
        ->and($query->fresh()->document_id)->toBeNull();

    Storage::disk('local')->assertMissing($document->disk_path);
    Storage::disk('local')->assertMissing($document->rawTextPath());
});

test('deleting a document that has no on-disk files yet does not fail', function () {
    Storage::fake('local');

    $document = Document::factory()->pending()->create(['disk_path' => 'documents/never-stored.pdf']);

    $response = $this->delete(route('documents.destroy', $document));

    $response->assertRedirect(route('documents.index'));
    expect(Document::find($document->id))->toBeNull();
});
