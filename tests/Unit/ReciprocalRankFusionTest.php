<?php

use App\Services\Retrieval\ReciprocalRankFusion;

test('a chunk ranked consistently well across both lists beats one that is only great in one list', function () {
    $fusion = new ReciprocalRankFusion(k: 60);

    // A: dense #1, lexical #3 -> 1/61 + 1/63 = 0.032266...
    // B: dense #2, lexical #1 -> 1/62 + 1/61 = 0.032522...
    // C: dense #3, lexical #2 -> 1/63 + 1/62 = 0.032002...
    // Expected order: B > A > C
    $dense = [1, 2, 3]; // A, B, C
    $sparse = [2, 3, 1]; // B, C, A

    $result = $fusion->fuse([$dense, $sparse], limit: 3);

    expect($result)->toBe([2, 1, 3]);
});

test('a chunk present in both lists outranks one present in only a single list', function () {
    $fusion = new ReciprocalRankFusion(k: 60);

    $dense = [1, 2];
    $sparse = [2];

    $result = $fusion->fuse([$dense, $sparse], limit: 2);

    // chunk 2: 1/62 + 1/61 = 0.032522...; chunk 1: 1/61 = 0.016393...
    expect($result)->toBe([2, 1]);
});

test('the fused list respects the limit', function () {
    $fusion = new ReciprocalRankFusion(k: 60);

    $result = $fusion->fuse([[1, 2, 3, 4, 5]], limit: 2);

    expect($result)->toBe([1, 2]);
});

test('a k of zero still produces a stable order for a single ranked list', function () {
    $fusion = new ReciprocalRankFusion(k: 0);

    $result = $fusion->fuse([[10, 20, 30]], limit: 3);

    expect($result)->toBe([10, 20, 30]);
});
