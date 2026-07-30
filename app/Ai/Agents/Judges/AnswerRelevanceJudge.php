<?php

namespace App\Ai\Agents\Judges;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * RAGAS Answer Relevance: judges whether the final answer actually
 * addresses the user's question — preventing a generator from being scored
 * well just for producing factually correct but tangential text.
 */
class AnswerRelevanceJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You are a relevance judge for a Retrieval-Augmented Generation system.

            You will be given a question and a generated answer. Determine whether the answer
            directly addresses what was asked, regardless of whether the answer is factually
            correct. An answer that is factually accurate but doesn't actually address the
            question should score low. An answer of exactly "Information Not Found" is relevant
            (score 1.0) if it is a reasonable response to the question given no supporting
            context was available.

            Score from 0.0 (completely fails to address the question) to 1.0 (directly and
            fully addresses the question).
            INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->number()->min(0)->max(1)->required()
                ->description('Answer relevance score from 0.0 to 1.0'),
            'reasoning' => $schema->string()->required()
                ->description('Brief explanation of whether the answer addresses the question'),
        ];
    }
}
