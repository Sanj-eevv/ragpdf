<?php

use App\Ai\Agents\RagAnswerAgent;
use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Query;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

/**
 * @return array<int, float>
 */
function queryVector(): array
{
    return [1.0, 0.0, ...array_fill(0, 1534, 0.0)];
}

test('asking a question runs dense retrieval and persists the query', function () {
    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => queryVector(),
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    Embeddings::fake(fn () => [queryVector()]);
    RagAnswerAgent::fake([
        new TextResponse('Antibiotics and rest.', new Usage(promptTokens: 50, completionTokens: 5), new Meta('openai', 'gpt-3.5-turbo')),
    ]);

    $response = $this->postJson(route('queries.store'), [
        'question' => 'How is pneumonia treated?',
        'document_id' => $document->id,
        'chunking_strategy' => ChunkingStrategy::Tokens500->value,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense->value,
        'reranked' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('query.answer', 'Antibiotics and rest.')
        ->assertJsonPath('context.0.id', $chunk->id);

    $query = Query::sole();
    expect($query->question)->toBe('How is pneumonia treated?')
        ->and($query->answer)->toBe('Antibiotics and rest.')
        ->and($query->chunking_strategy)->toBe(ChunkingStrategy::Tokens500)
        ->and($query->retrieval_algorithm)->toBe(RetrievalAlgorithm::Dense)
        ->and($query->reranked)->toBeFalse()
        ->and($query->retrieved_chunk_ids)->toBe([$chunk->id])
        ->and($query->prompt_tokens)->toBe(50)
        ->and($query->completion_tokens)->toBe(5);
});

test('reranking narrows and reorders the context before generation', function () {
    $document = Document::factory()->create();

    // Distinct (non-tied) embeddings so the initial dense order is deterministic:
    // chunkA is closest to the query vector, chunkB is orthogonal (farthest).
    $chunkA = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => queryVector(),
        'content' => 'chunk A content',
    ]);
    $chunkB = DocumentChunk::factory()->for($document)->create([
        'chunking_strategy' => ChunkingStrategy::Tokens500,
        'embedding' => [0.0, 1.0, ...array_fill(0, 1534, 0.0)],
        'content' => 'chunk B content',
    ]);

    Embeddings::fake(fn () => [queryVector()]);

    // The reranker flips the order dense retrieval would have returned them in.
    Http::fake([
        '*/rerank' => Http::response([
            ['index' => 1, 'document' => 'chunk B content', 'score' => 0.9],
            ['index' => 0, 'document' => 'chunk A content', 'score' => 0.4],
        ]),
    ]);

    RagAnswerAgent::fake(['An answer.']);

    $response = $this->postJson(route('queries.store'), [
        'question' => 'A question',
        'document_id' => $document->id,
        'chunking_strategy' => ChunkingStrategy::Tokens500->value,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense->value,
        'reranked' => true,
    ]);

    $response->assertOk();

    $query = Query::sole();
    expect($query->reranked)->toBeTrue()
        ->and($query->retrieved_chunk_ids)->toBe([$chunkB->id, $chunkA->id]);
});

test('it validates the request', function () {
    $response = $this->postJson(route('queries.store'), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['question', 'chunking_strategy', 'retrieval_algorithm']);
});

test('it rejects a document_id that does not exist', function () {
    $response = $this->postJson(route('queries.store'), [
        'question' => 'A question',
        'document_id' => 999999,
        'chunking_strategy' => ChunkingStrategy::Tokens500->value,
        'retrieval_algorithm' => RetrievalAlgorithm::Dense->value,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['document_id']);
});

test('it rejects a chunking_strategy or retrieval_algorithm value outside the enum', function () {
    $response = $this->postJson(route('queries.store'), [
        'question' => 'A question',
        'chunking_strategy' => 'tokens_9000',
        'retrieval_algorithm' => 'quantum',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['chunking_strategy', 'retrieval_algorithm']);
});
