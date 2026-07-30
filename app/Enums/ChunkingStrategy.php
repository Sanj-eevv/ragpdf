<?php

namespace App\Enums;

enum ChunkingStrategy: string
{
    case Tokens500 = 'tokens_500';
    case Tokens1000 = 'tokens_1000';

    public function tokenSize(): int
    {
        return match ($this) {
            self::Tokens500 => 500,
            self::Tokens1000 => 1000,
        };
    }

    public function overlapTokens(): int
    {
        return (int) round($this->tokenSize() * 0.1);
    }
}
