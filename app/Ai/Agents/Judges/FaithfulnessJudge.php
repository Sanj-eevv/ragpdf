<?php

namespace App\Ai\Agents\Judges;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * RAGAS Faithfulness: judges the generator — this is the hallucination
 * check. Extracts each claim made in the generated answer and verifies it
 * can be directly deduced from the retrieved context; any claim that can't
 * be supported is a hallucination and lowers the score.
 */
class FaithfulnessJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a hallucination judge for a Retrieval-Augmented Generation system.

You will be given a generated answer and the retrieved context it was supposed to
be based on. Break the answer down into its individual factual claims, then verify
each claim can be directly deduced from the retrieved context. A claim that cannot
be logically deduced from the context is a hallucination.

Score from 0.0 (no claims are supported by the context) to 1.0 (every claim is
fully supported by the context). If the answer is exactly "Information Not Found",
score 1.0 (it makes no unsupported claims).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->number()->min(0)->max(1)->required()
                ->description('Faithfulness score from 0.0 to 1.0'),
            'unsupported_claims' => $schema->array()->items($schema->string())->required()
                ->description('Claims in the answer that could not be deduced from the context (empty array if none)'),
        ];
    }
}
