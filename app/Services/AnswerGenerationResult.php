<?php

namespace App\Services;

readonly class AnswerGenerationResult
{
    public function __construct(
        public string $answer,
        public int $promptTokens,
        public int $completionTokens,
        public int $latencyMs,
    ) {}
}
