<?php

use App\Enums\ChunkingStrategy;
use App\Services\TextSplitter;

beforeEach(function () {
    $this->splitter = new TextSplitter;
});

test('short text stays as a single chunk', function () {
    $chunks = $this->splitter->split('A short paragraph about pneumonia treatment.', ChunkingStrategy::Tokens500);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe('A short paragraph about pneumonia treatment.');
});

test('long text is split into multiple chunks that respect the token budget', function () {
    $paragraph = 'Pneumonia is a respiratory illness that requires antibiotic treatment and rest. ';
    $text = str_repeat($paragraph, 60); // ~4900 chars, well past the 500-token (~2000 char) budget

    $chunks = $this->splitter->split($text, ChunkingStrategy::Tokens500);

    expect(count($chunks))->toBeGreaterThan(1);

    // ~2000 chars is the 500-token budget; allow slack since merging works on whole pieces.
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThan(2000 * 1.5);
    }
});

test('consecutive chunks overlap', function () {
    $paragraph = 'Pneumonia is a respiratory illness that requires antibiotic treatment and rest. ';
    $text = str_repeat($paragraph, 60);

    $chunks = $this->splitter->split($text, ChunkingStrategy::Tokens500);

    expect(count($chunks))->toBeGreaterThan(1);

    // The tail of each chunk should reappear at the start of the next chunk (the overlap).
    $tail = mb_substr($chunks[0], -20);
    expect($chunks[1])->toContain(trim($tail));
});

test('a 1000-token strategy produces fewer, larger chunks than a 500-token strategy for the same text', function () {
    $paragraph = 'Pneumonia is a respiratory illness that requires antibiotic treatment and rest. ';
    $text = str_repeat($paragraph, 120);

    $chunks500 = $this->splitter->split($text, ChunkingStrategy::Tokens500);
    $chunks1000 = $this->splitter->split($text, ChunkingStrategy::Tokens1000);

    expect(count($chunks1000))->toBeLessThan(count($chunks500));
});

test('token count is a reasonable approximation of character length', function () {
    $text = str_repeat('a', 400);

    expect($this->splitter->tokenCount($text))->toBe(100);
});
