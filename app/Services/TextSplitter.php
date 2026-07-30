<?php

namespace App\Services;

use App\Enums\ChunkingStrategy;

/**
 * Recursive Character Text Splitter: tries to keep related text together by
 * splitting on paragraphs first, falling back to lines, sentences, then raw
 * characters only when a piece is still too big for the target chunk size.
 *
 * Token counts are approximated at ~4 characters per token (a standard rule
 * of thumb for GPT-style tokenizers) rather than using a real BPE tokenizer,
 * to keep this dependency-free.
 */
class TextSplitter
{
    /** @var array<int, string> */
    private const array SEPARATORS = ["\n\n", "\n", '. ', ''];

    private const int CHARS_PER_TOKEN = 4;

    /**
     * @return array<int, string> ordered chunk contents, with ~10% overlap between consecutive chunks
     */
    public function split(string $text, ChunkingStrategy $strategy): array
    {
        $chunkChars = $strategy->tokenSize() * self::CHARS_PER_TOKEN;
        $overlapChars = $strategy->overlapTokens() * self::CHARS_PER_TOKEN;

        $pieces = $this->recursiveSplit(trim($text), $chunkChars, self::SEPARATORS);

        return $this->mergeWithOverlap($pieces, $chunkChars, $overlapChars);
    }

    public function tokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * @param  array<int, string>  $separators
     * @return array<int, string>
     */
    private function recursiveSplit(string $text, int $chunkChars, array $separators): array
    {
        if ($text === '' || mb_strlen($text) <= $chunkChars || $separators === []) {
            return $text === '' ? [] : [$text];
        }

        $separator = $separators[0];
        $remaining = array_slice($separators, 1);

        $parts = $separator === ''
            ? mb_str_split($text)
            : explode($separator, $text);

        $pieces = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (mb_strlen($part) > $chunkChars) {
                array_push($pieces, ...$this->recursiveSplit($part, $chunkChars, $remaining));
            } else {
                $pieces[] = $part;
            }
        }

        return $pieces;
    }

    /**
     * @param  array<int, string>  $pieces
     * @return array<int, string>
     */
    private function mergeWithOverlap(array $pieces, int $chunkChars, int $overlapChars): array
    {
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current."\n".$piece;

            if (mb_strlen($candidate) > $chunkChars && $current !== '') {
                $chunks[] = trim($current);
                $overlap = trim(mb_substr($current, -$overlapChars));
                $current = $overlap === '' ? $piece : $overlap."\n".$piece;
            } else {
                $current = $candidate;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
