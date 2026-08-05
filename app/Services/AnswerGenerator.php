<?php

namespace App\Services;

use App\Ai\Agents\RagAnswerAgent;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;

/**
 * Generates the final answer via `gemini-3.5-flash-lite`, per the thesis's
 * choice of a fast, cheap model for the generation step (explicit, since the
 * AI SDK's Gemini default is a newer/smarter model).
 */
class AnswerGenerator
{
    private const string MODEL = 'gemini-3.5-flash-lite';

    /**
     * @param  Collection<int, DocumentChunk>  $context
     */
    public function generate(string $question, Collection $context): AnswerGenerationResult
    {
        $prompt = $this->buildPrompt($question, $context);

        $startedAt = microtime(true);
        $response = (new RagAnswerAgent)->prompt($prompt, model: self::MODEL);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new AnswerGenerationResult(
            answer: $response->text,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  Collection<int, DocumentChunk>  $context
     */
    private function buildPrompt(string $question, Collection $context): string
    {
        $chunks = $context->values();

        $contextText = $chunks->isEmpty()
            ? '(no context retrieved)'
            : $chunks
                ->map(fn (DocumentChunk $chunk, int $index) => "[{$index}] {$chunk->content}")
                ->implode("\n\n");

        return <<<PROMPT
            Context:
            {$contextText}

            Question: {$question}
            PROMPT;
    }
}
