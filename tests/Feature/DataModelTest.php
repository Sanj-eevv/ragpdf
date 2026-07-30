<?php

use App\Enums\ChunkingStrategy;
use App\Enums\DocumentStatus;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Query;
use App\Models\RagasEvaluation;

test('a document has chunks and queries, with the status enum cast', function () {
    $document = Document::factory()->pending()->create();

    expect($document->status)->toBe(DocumentStatus::Pending);

    $chunk = DocumentChunk::factory()->for($document)->create();
    $query = Query::factory()->for($document)->create();

    expect($document->chunks)->toHaveCount(1)
        ->and($document->chunks->first()->is($chunk))->toBeTrue()
        ->and($document->queries)->toHaveCount(1)
        ->and($document->queries->first()->is($query))->toBeTrue();
});

test('a document chunk casts its embedding to an array and its strategy to an enum', function () {
    $chunk = DocumentChunk::factory()->create([
        'chunking_strategy' => ChunkingStrategy::Tokens1000,
        'embedding' => array_fill(0, 1536, 0.5),
    ]);

    $chunk->refresh();

    expect($chunk->chunking_strategy)->toBe(ChunkingStrategy::Tokens1000)
        ->and($chunk->embedding)->toBeArray()
        ->and($chunk->embedding)->toHaveCount(1536);
});

test('vector similarity search finds the closest chunk by cosine distance', function () {
    $document = Document::factory()->create();

    $closest = DocumentChunk::factory()->for($document)->create([
        'content' => 'closest chunk',
        'embedding' => [1.0, 0.0, ...array_fill(0, 1534, 0.0)],
    ]);

    DocumentChunk::factory()->for($document)->create([
        'content' => 'far chunk',
        'embedding' => [0.0, 1.0, ...array_fill(0, 1534, 0.0)],
    ]);

    $results = DocumentChunk::query()
        ->whereVectorSimilarTo('embedding', [1.0, 0.0, ...array_fill(0, 1534, 0.0)])
        ->limit(1)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->is($closest))->toBeTrue();
});

test('full text search matches chunk content', function () {
    $document = Document::factory()->create();

    $match = DocumentChunk::factory()->for($document)->create([
        'content' => 'The treatment for pneumonia includes antibiotics and rest.',
    ]);

    DocumentChunk::factory()->for($document)->create([
        'content' => 'General healthcare guidelines for winter flu prevention.',
    ]);

    $results = DocumentChunk::query()
        ->whereFullText('content', 'pneumonia')
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->is($match))->toBeTrue();
});

test('a query has one ragas evaluation, with casts applied', function () {
    $query = Query::factory()->create([
        'retrieval_algorithm' => RetrievalAlgorithm::Hybrid,
        'reranked' => true,
        'retrieved_chunk_ids' => [1, 2, 3],
    ]);

    $evaluation = RagasEvaluation::factory()->for($query, 'queryRecord')->create();

    $query->refresh();

    expect($query->retrieval_algorithm)->toBe(RetrievalAlgorithm::Hybrid)
        ->and($query->reranked)->toBeTrue()
        ->and($query->retrieved_chunk_ids)->toBe([1, 2, 3])
        ->and($query->ragasEvaluation->is($evaluation))->toBeTrue()
        ->and($evaluation->raw_judge_response)->toBeArray();
});
