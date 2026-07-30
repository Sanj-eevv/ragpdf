<?php

namespace App\Services\Retrieval;

/**
 * Fuses multiple ranked ID lists into a single ranking using Reciprocal Rank
 * Fusion: RRF_Score = sum(1 / (k + rank)) across every list a given ID
 * appears in. Operates purely on ranks (list position), not raw scores,
 * since cosine similarity and ts_rank are not on comparable scales.
 */
class ReciprocalRankFusion
{
    public function __construct(private readonly int $k = 60) {}

    /**
     * @param  array<int, array<int, int>>  $rankedIdLists  each inner array is an ordered list of IDs, best first
     * @return array<int, int> IDs ordered by fused score, best first
     */
    public function fuse(array $rankedIdLists, int $limit): array
    {
        $scores = [];

        foreach ($rankedIdLists as $rankedIds) {
            foreach (array_values($rankedIds) as $index => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / ($this->k + $index + 1);
            }
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, $limit);
    }
}
