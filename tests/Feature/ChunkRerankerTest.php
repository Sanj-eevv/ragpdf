<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Retrieval\ChunkReranker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('reranker reorders and truncates chunks based on the service response', function () {
    $document = Document::factory()->create();

    $chunkA = DocumentChunk::factory()->for($document)->create(['content' => 'chunk A content']);
    $chunkB = DocumentChunk::factory()->for($document)->create(['content' => 'chunk B content']);
    $chunkC = DocumentChunk::factory()->for($document)->create(['content' => 'chunk C content']);

    // Input order to the reranker is [A, B, C] (indices 0, 1, 2). The fake
    // service says C is most relevant, A second, and B isn't returned at all.
    Http::fake([
        '*/rerank' => Http::response([
            ['index' => 2, 'document' => 'chunk C content', 'score' => 0.9],
            ['index' => 0, 'document' => 'chunk A content', 'score' => 0.5],
        ]),
    ]);

    $chunks = new Collection([$chunkA, $chunkB, $chunkC]);

    $result = (new ChunkReranker)->rerank($chunks, 'a question about chunk C', limit: 2);

    expect($result->pluck('id')->all())->toBe([$chunkC->id, $chunkA->id]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rerank')
        && $request['query'] === 'a question about chunk C'
        && $request['documents'] === ['chunk A content', 'chunk B content', 'chunk C content']
        && $request['limit'] === 2);
});

test('reranker returns an empty collection without calling the service when given no chunks', function () {
    Http::fake();

    $result = (new ChunkReranker)->rerank(new Collection, 'a question');

    expect($result)->toBeEmpty();

    Http::assertNothingSent();
});

test('reranker throws when the service responds with an error', function () {
    Http::fake(['*/rerank' => Http::response(['detail' => 'model failure'], 500)]);

    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create();

    (new ChunkReranker)->rerank(new Collection([$chunk]), 'a question');
})->throws(RequestException::class);
