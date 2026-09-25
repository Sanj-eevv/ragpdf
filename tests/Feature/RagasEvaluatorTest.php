<?php

use App\Ai\Agents\Judges\AnswerRelevanceJudge;
use App\Ai\Agents\Judges\ContextPrecisionJudge;
use App\Ai\Agents\Judges\ContextRecallJudge;
use App\Ai\Agents\Judges\FaithfulnessJudge;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Query;
use App\Services\Evaluation\RagasEvaluator;

test('it scores all four metrics and persists the raw judge responses when a ground truth is given', function () {
    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create([
        'content' => 'Pneumonia is treated with antibiotics and rest.',
    ]);

    $query = Query::factory()->for($document)->create([
        'question' => 'How is pneumonia treated?',
        'answer' => 'Antibiotics and rest.',
        'retrieved_chunk_ids' => [$chunk->id],
    ]);

    ContextPrecisionJudge::fake([['verdicts' => [['rank' => 0, 'relevant' => true, 'reason' => 'Chunk directly answers the question.']]]]);
    ContextRecallJudge::fake([['statements' => [['statement' => 'Pneumonia is treated with antibiotics.', 'attributed' => true, 'reason' => 'Found in chunk.'], ['statement' => 'Pneumonia is treated with rest.', 'attributed' => true, 'reason' => 'Found in chunk.']]]]);
    FaithfulnessJudge::fake([['claims' => [['claim' => 'Antibiotics treat pneumonia.', 'supported' => true, 'reason' => 'Supported by chunk.'], ['claim' => 'Rest treats pneumonia.', 'supported' => true, 'reason' => 'Supported by chunk.']]]]);
    AnswerRelevanceJudge::fake([['requirements' => [['requirement' => 'Explain how pneumonia is treated.', 'addressed' => true, 'reason' => 'Directly answers the question.'], ['requirement' => 'Name the specific treatments.', 'addressed' => false, 'reason' => 'Vague about dosage.']]]]);

    $evaluation = (new RagasEvaluator)->evaluate($query, groundTruthAnswer: 'Antibiotics and rest.');

    expect($evaluation->query_id)->toBe($query->id)
        ->and($evaluation->context_precision)->toBe(1.0)
        ->and($evaluation->context_recall)->toBe(1.0)
        ->and($evaluation->faithfulness)->toBe(1.0)
        ->and($evaluation->answer_relevance)->toBe(0.5)
        ->and($evaluation->judge_model)->toBe('gemini-3.6-flash')
        ->and($evaluation->raw_judge_response['context_precision']['verdicts'][0]['reason'])->toBe('Chunk directly answers the question.');

    ContextPrecisionJudge::assertPrompted(fn ($prompt) => $prompt->contains('How is pneumonia treated?')
        && $prompt->contains('Pneumonia is treated with antibiotics and rest.'));
    ContextRecallJudge::assertPrompted(fn ($prompt) => $prompt->contains('Antibiotics and rest.'));
    FaithfulnessJudge::assertPrompted(fn ($prompt) => $prompt->contains('Antibiotics and rest.'));
    AnswerRelevanceJudge::assertPrompted(fn ($prompt) => $prompt->contains('How is pneumonia treated?'));
});

test('context recall is left null and never prompted when there is no ground truth answer', function () {
    $document = Document::factory()->create();
    $chunk = DocumentChunk::factory()->for($document)->create();

    $query = Query::factory()->for($document)->create([
        'retrieved_chunk_ids' => [$chunk->id],
    ]);

    ContextPrecisionJudge::fake([['verdicts' => [['rank' => 0, 'relevant' => false, 'reason' => 'ok']]]]);
    ContextRecallJudge::fake();
    FaithfulnessJudge::fake([['claims' => [['claim' => 'x', 'supported' => false, 'reason' => 'ok']]]]);
    AnswerRelevanceJudge::fake([['requirements' => [['requirement' => 'x', 'addressed' => false, 'reason' => 'ok']]]]);

    $evaluation = (new RagasEvaluator)->evaluate($query);

    expect($evaluation->context_recall)->toBeNull()
        ->and($evaluation->raw_judge_response['context_recall'])->toBeNull();

    ContextRecallJudge::assertNeverPrompted();
});

test('it gracefully handles a query with no retrieved chunks', function () {
    $document = Document::factory()->create();

    $query = Query::factory()->for($document)->create([
        'answer' => 'Information Not Found',
        'retrieved_chunk_ids' => [],
    ]);

    ContextPrecisionJudge::fake([['verdicts' => []]]);
    FaithfulnessJudge::fake([['claims' => []]]);
    AnswerRelevanceJudge::fake([['requirements' => [['requirement' => 'Acknowledge no supporting information is available.', 'addressed' => true, 'reason' => 'Correctly reports missing information.']]]]);

    $evaluation = (new RagasEvaluator)->evaluate($query);

    expect($evaluation->context_precision)->toBe(0.0)
        ->and($evaluation->faithfulness)->toBe(1.0);

    ContextPrecisionJudge::assertPrompted(fn ($prompt) => $prompt->contains('(no context retrieved)'));
});
