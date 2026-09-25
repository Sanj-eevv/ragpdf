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
 *
 * Decomposition + per-requirement verdicts (rather than a single freehand
 * score) keeps this consistent with the other three judges and is far more
 * reproducible run to run — RagasEvaluator computes the score as
 * addressed/total over these discrete verdicts.
 */
class AnswerRelevanceJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a relevance judge for a Retrieval-Augmented Generation system.

You will be given a question and a generated answer. First, break the question
down into the distinct informational requirements a fully relevant answer would
need to satisfy (what is it actually asking for?) — usually just one requirement,
occasionally more for a compound question. For EVERY requirement, decide whether
the answer addresses it, regardless of whether the answer is factually correct: an
answer that is factually accurate but doesn\'t actually address the question fails
that requirement.

Be strict and binary — do not report a fuzzy score. An answer of exactly
"Information Not Found" satisfies its single requirement ("acknowledge that no
supporting information is available") when no supporting context was available.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'requirements' => $schema->array()->items(
                $schema->object([
                    'requirement' => $schema->string()->required()
                        ->description('One distinct informational requirement the question is asking for'),
                    'addressed' => $schema->boolean()->required()
                        ->description('Whether the generated answer addresses this requirement'),
                    'reason' => $schema->string()->required()
                        ->description('One sentence justifying the verdict'),
                ])
            )->required()->description('Every distinct informational requirement of the question, each with its own addressed verdict'),
        ];
    }
}
