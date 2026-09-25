<?php

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Retrieval\DenseRetriever;
use App\Services\Retrieval\HybridRetriever;
use App\Services\Retrieval\RetrievalService;
use Illuminate\Support\Facades\Http;

/**
 * @return array<int, float> a 384-dim vector with `$value` at position 0 and `$second` at position 1, zeros elsewhere
 */
function vectorWith(float $value, float $second = 0.0): array
{
    return [$value, $second, ...array_fill(0, 382, 0.0)];
}

test('dense retriever orders chunks by cosine similarity to the query', function () {
    $document = Document::factory()->create();

    $closest = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(1.0),
    ]);
    $middle = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(0.8, 0.6),
    ]);
    $farthest = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(0.0, 1.0),
    ]);

    Http::fake(['*/embed' => Http::response(['embeddings' => [vectorWith(1.0)]])]);

    $results = app(DenseRetriever::class)->search('irrelevant question text', ChunkingStrategy::Tokens500, $document->id);

    expect($results->pluck('id')->all())->toBe([$closest->id, $middle->id, $farthest->id]);
});

test('dense retriever filters by chunking strategy and document', function () {
    $document = Document::factory()->create();
    $otherDocument = Document::factory()->create();

    $wantedStrategy = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(1.0),
    ]);
    DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens1000,
        'embedding' => vectorWith(1.0),
    ]);
    DocumentChunk::factory()->for($otherDocument)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(1.0),
    ]);

    Http::fake(['*/embed' => Http::response(['embeddings' => [vectorWith(1.0)]])]);

    $results = app(DenseRetriever::class)->search('question', ChunkingStrategy::Tokens500, $document->id);

    expect($results->pluck('id')->all())->toBe([$wantedStrategy->id]);
});

test('hybrid retriever fuses dense and lexical rankings via RRF', function () {
    $document = Document::factory()->create();

    // Closest by embedding AND the strongest lexical match -> should rank first.
    $both = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(1.0),
        'content' => 'pneumonia pneumonia pneumonia treatment information',
    ]);

    // Second-closest by embedding, but never mentions the query term at all.
    $denseOnly = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(0.8, 0.6),
        'content' => 'completely unrelated financial audit guidelines',
    ]);

    // Orthogonal embedding (least similar), but does mention the query term once.
    $sparseOnly = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(0.0, 1.0),
        'content' => 'a brief mention of pneumonia in passing',
    ]);

    Http::fake(['*/embed' => Http::response(['embeddings' => [vectorWith(1.0)]])]);

    $results = app(HybridRetriever::class)->search('pneumonia', ChunkingStrategy::Tokens500, $document->id);

    // RRF (k=60): both = 1/61+1/61 = 0.0328; sparseOnly = 1/63+1/62 = 0.0320; denseOnly = 1/62 = 0.0161.
    expect($results->pluck('id')->all())->toBe([$both->id, $sparseOnly->id, $denseOnly->id]);
});

test('hybrid retriever still finds lexical matches when the question is a full natural-language sentence', function () {
    $document = Document::factory()->create();

    // Weakest dense match, but the only chunk that lexically matches the
    // question at all. Regression test: plainto_tsquery used to AND every
    // word of the question together, so a full sentence practically never
    // matched any chunk, the sparse leg of RRF silently contributed
    // nothing, and hybrid search collapsed to exactly dense search.
    $lexicalOnly = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(0.0, 1.0),
        'content' => 'Livewire lets you build reactive components without writing JavaScript.',
    ]);

    $denseOnly = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(1.0),
        'content' => 'completely unrelated financial audit guidelines',
    ]);

    Http::fake(['*/embed' => Http::response(['embeddings' => [vectorWith(1.0)]])]);

    $question = 'How does Laravel Livewire let you build reactive components without writing JavaScript?';

    $dense = app(DenseRetriever::class)->search($question, ChunkingStrategy::Tokens500, $document->id);
    $hybrid = app(HybridRetriever::class)->search($question, ChunkingStrategy::Tokens500, $document->id);

    expect($dense->pluck('id')->all())->toBe([$denseOnly->id, $lexicalOnly->id])
        ->and($hybrid->pluck('id')->all())->not->toBe($dense->pluck('id')->all())
        ->and($hybrid->first()->id)->toBe($lexicalOnly->id);
});

test('retrieval service dispatches to the retriever matching the requested algorithm', function () {
    $document = Document::factory()->create();

    $chunk = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => vectorWith(1.0),
        'content' => 'pneumonia treatment information',
    ]);

    Http::fake(['*/embed' => Http::response(['embeddings' => [vectorWith(1.0)]])]);

    $service = app(RetrievalService::class);

    $dense = $service->search(RetrievalAlgorithm::Dense, 'pneumonia', ChunkingStrategy::Tokens500, $document->id);
    $hybrid = $service->search(RetrievalAlgorithm::Hybrid, 'pneumonia', ChunkingStrategy::Tokens500, $document->id);

    expect($dense->pluck('id')->all())->toBe([$chunk->id])
        ->and($hybrid->pluck('id')->all())->toBe([$chunk->id]);
});
