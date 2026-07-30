<?php

namespace App\Ai\Agents\Judges;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * RAGAS Context Precision: judges the retriever/re-ranker, not the generator.
 * Scores how much of the retrieved context is actually useful evidence for
 * answering the question, penalizing systems that bury relevant evidence
 * below irrelevant noise (signal-to-noise ratio and rank position both matter).
 */
class ContextPrecisionJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You are an evidence-quality judge for a Retrieval-Augmented Generation system.

            You will be given a question and a ranked list of retrieved text chunks (rank 0
            is the highest-ranked / most-relevant-per-the-retriever chunk). Determine how much
            of the retrieved context is actually necessary evidence for answering the question,
            versus irrelevant noise. Penalize the score if genuinely relevant chunks are ranked
            low while irrelevant chunks are ranked high.

            Score from 0.0 (no retrieved chunk is useful) to 1.0 (every retrieved chunk is
            useful and well-ranked).
            INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->number()->min(0)->max(1)->required()
                ->description('Context precision score from 0.0 to 1.0'),
            'reasoning' => $schema->string()->required()
                ->description('Brief explanation of which chunks were useful vs noise, and any rank-order penalty applied'),
        ];
    }
}
