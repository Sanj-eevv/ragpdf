<?php

namespace Database\Factories;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use App\Models\Document;
use App\Models\Query;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Query>
 */
class QueryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'question' => $this->faker->sentence().'?',
            'answer' => $this->faker->paragraph(),
            'chunking_strategy' => ChunkingStrategy::Tokens500,
            'retrieval_algorithm' => RetrievalAlgorithm::Dense,
            'reranked' => false,
            'retrieved_chunk_ids' => [],
            'latency_ms' => $this->faker->numberBetween(200, 5000),
            'prompt_tokens' => $this->faker->numberBetween(100, 2000),
            'completion_tokens' => $this->faker->numberBetween(10, 500),
        ];
    }
}
