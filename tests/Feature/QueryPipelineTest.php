<?php

use App\Ai\Agents\RagAnswerAgent;
use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\QueryPipeline;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

test('without reranking, it persists a Query using retrieval order and the generated answer as-is', function () {
    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => [1.0, ...array_fill(0, 1535, 0.0)],
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    Embeddings::fake(fn () => [[1.0, ...array_fill(0, 1535, 0.0)]]);
    RagAnswerAgent::fake([
        new TextResponse('Antibiotics and rest.', new Usage(promptTokens: 42, completionTokens: 7), new Meta('openai', 'gpt-3.5-turbo')),
    ]);

    ['query' => $query, 'chunks' => $chunks] = app(QueryPipeline::class)->run(
        'How is pneumonia treated?',
        $document->id,
        ChunkingStrategy::Tokens500,
        RetrievalAlgorithm::Dense,
        reranked: false,
    );

    expect($query->exists)->toBeTrue()
        ->and($query->question)->toBe('How is pneumonia treated?')
        ->and($query->answer)->toBe('Antibiotics and rest.')
        ->and($query->reranked)->toBeFalse()
        ->and($query->retrieved_chunk_ids)->toBe([$chunk->id])
        ->and($query->prompt_tokens)->toBe(42)
        ->and($query->completion_tokens)->toBe(7)
        ->and($chunks->pluck('id')->all())->toBe([$chunk->id]);

    Reranking::assertNothingReranked();
});

test('with reranking, it persists a Query using the reranked order instead of retrieval order', function () {
    $document = Document::factory()->create();

    $chunkA = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => [1.0, ...array_fill(0, 1535, 0.0)],
        'content' => 'chunk A content',
    ]);
    $chunkB = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => [0.0, 1.0, ...array_fill(0, 1534, 0.0)],
        'content' => 'chunk B content',
    ]);

    // Dense retrieval would rank chunkA first (closer to the query vector);
    // the reranker flips that order.
    Embeddings::fake(fn () => [[1.0, ...array_fill(0, 1535, 0.0)]]);
    Reranking::fake(fn () => [
        new RankedDocument(index: 1, document: 'chunk B content', score: 0.9),
        new RankedDocument(index: 0, document: 'chunk A content', score: 0.4),
    ]);
    RagAnswerAgent::fake(['An answer.']);

    ['query' => $query, 'chunks' => $chunks] = app(QueryPipeline::class)->run(
        'A question',
        $document->id,
        ChunkingStrategy::Tokens500,
        RetrievalAlgorithm::Dense,
        reranked: true,
    );

    expect($query->reranked)->toBeTrue()
        ->and($query->retrieved_chunk_ids)->toBe([$chunkB->id, $chunkA->id])
        ->and($chunks->pluck('id')->all())->toBe([$chunkB->id, $chunkA->id]);
});

test('it works without a document_id, searching across all documents', function () {
    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => [1.0, ...array_fill(0, 1535, 0.0)],
    ]);

    Embeddings::fake(fn () => [[1.0, ...array_fill(0, 1535, 0.0)]]);
    RagAnswerAgent::fake(['An answer.']);

    ['query' => $query] = app(QueryPipeline::class)->run(
        'A question',
        null,
        ChunkingStrategy::Tokens500,
        RetrievalAlgorithm::Dense,
        reranked: false,
    );

    expect($query->document_id)->toBeNull()
        ->and($query->retrieved_chunk_ids)->toBe([$chunk->id]);
});
