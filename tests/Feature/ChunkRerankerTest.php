<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Retrieval\ChunkReranker;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

test('reranker reorders and truncates chunks based on the provider response', function () {
    $document = Document::factory()->create();

    $chunkA = DocumentChunk::factory()->for($document)->create(['content' => 'chunk A content']);
    $chunkB = DocumentChunk::factory()->for($document)->create(['content' => 'chunk B content']);
    $chunkC = DocumentChunk::factory()->for($document)->create(['content' => 'chunk C content']);

    // Input order to the reranker is [A, B, C] (indices 0, 1, 2). The fake
    // provider says C is most relevant, A second, and B isn't returned at all.
    Reranking::fake(fn () => [
        new RankedDocument(index: 2, document: 'chunk C content', score: 0.9),
        new RankedDocument(index: 0, document: 'chunk A content', score: 0.5),
    ]);

    $chunks = new Collection([$chunkA, $chunkB, $chunkC]);

    $result = (new ChunkReranker)->rerank($chunks, 'a question about chunk C', limit: 2);

    expect($result->pluck('id')->all())->toBe([$chunkC->id, $chunkA->id]);

    Reranking::assertReranked(fn ($prompt) => $prompt->contains('chunk C')
        && $prompt->documentsContain('chunk A content')
        && count($prompt) === 3);
});

test('reranker returns an empty collection without calling the provider when given no chunks', function () {
    Reranking::fake();

    $result = (new ChunkReranker)->rerank(new Collection, 'a question');

    expect($result)->toBeEmpty();

    Reranking::assertNothingReranked();
});
