<?php

use App\Enums\ChunkingStrategy;

test('the 500-token strategy has a 10% overlap of 50 tokens', function () {
    expect(ChunkingStrategy::Tokens500->tokenSize())->toBe(500)
        ->and(ChunkingStrategy::Tokens500->overlapTokens())->toBe(50);
});

test('the 1000-token strategy has a 10% overlap of 100 tokens', function () {
    expect(ChunkingStrategy::Tokens1000->tokenSize())->toBe(1000)
        ->and(ChunkingStrategy::Tokens1000->overlapTokens())->toBe(100);
});
