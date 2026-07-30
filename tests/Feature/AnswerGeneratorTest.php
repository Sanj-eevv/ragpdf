<?php

use App\Ai\Agents\RagAnswerAgent;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AnswerGenerator;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

test('it injects context chunks and the question into the prompt', function () {
    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create([
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    RagAnswerAgent::fake([
        new TextResponse('Antibiotics and rest.', new Usage(promptTokens: 120, completionTokens: 8), new Meta('openai', 'gpt-3.5-turbo')),
    ]);

    $result = (new AnswerGenerator)->generate('How is pneumonia treated?', new Collection([$chunk]));

    expect($result->answer)->toBe('Antibiotics and rest.')
        ->and($result->promptTokens)->toBe(120)
        ->and($result->completionTokens)->toBe(8)
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);

    RagAnswerAgent::assertPrompted(fn ($prompt) => $prompt->contains('Pneumonia is treated with antibiotics and rest.')
        && $prompt->contains('How is pneumonia treated?'));
});

test('the agent instructions enforce answering only from context', function () {
    expect((new RagAnswerAgent)->instructions())->toContain('Information Not Found')
        ->and((string) (new RagAnswerAgent)->instructions())->toContain('ONLY the');
});

test('it handles an empty context set without erroring', function () {
    RagAnswerAgent::fake(['Information Not Found']);

    $result = (new AnswerGenerator)->generate('An unanswerable question?', new Collection);

    expect($result->answer)->toBe('Information Not Found');

    RagAnswerAgent::assertPrompted(fn ($prompt) => $prompt->contains('(no context retrieved)'));
});
