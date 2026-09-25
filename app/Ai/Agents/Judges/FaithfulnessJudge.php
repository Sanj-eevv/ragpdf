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
 *
 * Decomposition + per-claim verdicts (rather than a single freehand score)
 * is the actual RAGAS methodology and is far more reproducible run to run —
 * RagasEvaluator computes the score as supported/total over these discrete
 * verdicts.
 */
class FaithfulnessJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a hallucination judge for a Retrieval-Augmented Generation system.

You will be given a generated answer and the retrieved context it was supposed to
be based on. Break the answer down into its individual atomic factual claims —
each claim should assert exactly one fact. For EVERY claim, decide whether it can
be directly deduced from the retrieved context. A claim that cannot be logically
deduced from the context is a hallucination.

Be strict and binary — do not report a fuzzy score. If the answer is exactly
"Information Not Found", it makes no factual claims: return an empty claims array.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'claims' => $schema->array()->items(
                $schema->object([
                    'claim' => $schema->string()->required()
                        ->description('One atomic factual claim extracted from the generated answer'),
                    'supported' => $schema->boolean()->required()
                        ->description('Whether this claim can be directly deduced from the retrieved context'),
                    'reason' => $schema->string()->required()
                        ->description('One sentence justifying the verdict'),
                ])
            )->required()->description('Every atomic factual claim in the generated answer, each with its own support verdict (empty array if the answer makes no claims)'),
        ];
    }
}
