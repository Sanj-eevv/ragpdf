<?php

namespace App\Ai\Agents\Judges;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * RAGAS Context Recall: judges the chunking algorithm. Compares retrieved
 * chunks against a verified ground-truth answer and computes what proportion
 * of the ground-truth facts are present in the retrieved text — exposing
 * whether chunking broke apart or missed critical data.
 *
 * Decomposition + per-statement verdicts (rather than a single freehand
 * score) is the actual RAGAS methodology and is far more reproducible run to
 * run — RagasEvaluator computes the score as attributed/total over these
 * discrete verdicts.
 */
class ContextRecallJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a completeness judge for a Retrieval-Augmented Generation system.

You will be given a verified ground-truth answer and a list of retrieved text
chunks. Break the ground-truth answer down into its individual atomic factual
statements — each statement should assert exactly one fact. For EVERY statement,
decide whether it is explicitly attributable to (found in) the retrieved chunks.

Be strict and binary — do not report a fuzzy score. A statement only counts as
attributed if the retrieved chunks actually contain that fact, not merely a
related topic.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'statements' => $schema->array()->items(
                $schema->object([
                    'statement' => $schema->string()->required()
                        ->description('One atomic factual statement extracted from the ground-truth answer'),
                    'attributed' => $schema->boolean()->required()
                        ->description('Whether this statement is explicitly found in the retrieved chunks'),
                    'reason' => $schema->string()->required()
                        ->description('One sentence justifying the verdict'),
                ])
            )->required()->description('Every atomic factual statement in the ground-truth answer, each with its own attribution verdict'),
        ];
    }
}
