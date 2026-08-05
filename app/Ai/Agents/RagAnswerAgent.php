<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Answers a question strictly from the context injected into the prompt
 * (see AnswerGenerator), refusing to fall back on outside knowledge — this
 * is how the thesis's "Accuracy Fallacy" gets tested: a faithful model
 * should say "Information Not Found" rather than hallucinate when the
 * retrieved context doesn't actually contain the answer.
 */
class RagAnswerAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a question-answering assistant. Answer the user\'s question using ONLY the
context provided in the prompt. Do not use any outside knowledge.

If the context does not contain enough information to answer the question, respond
with exactly: Information Not Found

Do not explain why the information is missing. Do not guess or speculate.';
    }
}
