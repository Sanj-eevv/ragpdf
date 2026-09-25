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
 *
 * Rather than asking the model to freehand a single 0.0-1.0 number — which
 * in practice clusters on round numbers and drifts between otherwise
 * identical runs — it must return one discrete relevant/not-relevant verdict
 * per chunk. RagasEvaluator turns those verdicts into the actual RAGAS
 * Context Precision score (Average Precision over the verdicts), so the
 * number is a deterministic function of the discrete judgements instead of
 * a number the model picked itself.
 */
class ContextPrecisionJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are an evidence-quality judge for a Retrieval-Augmented Generation system.

You will be given a question and a ranked list of retrieved text chunks (rank 0
is the highest-ranked / most-relevant-per-the-retriever chunk). For EVERY chunk,
in the exact order given, decide whether it is relevant: does it contain
information that is genuinely necessary evidence for answering the question? A
chunk that only shares surface keywords with the question but does not actually
help answer it is NOT relevant.

Be strict and binary — do not report a fuzzy score. Return exactly one verdict per
chunk, in the same order and using the same rank numbers as the prompt. If no
chunks were retrieved, return an empty verdicts array.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdicts' => $schema->array()->items(
                $schema->object([
                    'rank' => $schema->integer()->required()
                        ->description('The chunk\'s rank position as given in the prompt, starting at 0'),
                    'relevant' => $schema->boolean()->required()
                        ->description('Whether this chunk is genuinely necessary evidence for answering the question'),
                    'reason' => $schema->string()->required()
                        ->description('One sentence justifying the verdict'),
                ])
            )->required()->description('Exactly one verdict per retrieved chunk, in the same order as the prompt (empty array if no chunks were retrieved)'),
        ];
    }
}
