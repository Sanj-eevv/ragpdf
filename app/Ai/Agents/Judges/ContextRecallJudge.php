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
 */
class ContextRecallJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a completeness judge for a Retrieval-Augmented Generation system.

You will be given a verified ground-truth answer and a list of retrieved text
chunks. Break the ground-truth answer down into its individual factual statements,
then determine what proportion of those statements can be explicitly found within
the retrieved chunks.

Score from 0.0 (none of the ground-truth facts are present in the retrieved
context) to 1.0 (every ground-truth fact is present).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->number()->min(0)->max(1)->required()
                ->description('Context recall score from 0.0 to 1.0'),
            'missing_facts' => $schema->array()->items($schema->string())->required()
                ->description('Ground-truth facts that were NOT found in the retrieved chunks (empty array if none)'),
        ];
    }
}
