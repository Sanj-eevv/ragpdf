<?php

namespace Database\Factories;

use App\Enums\ChunkingStrategy;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = $this->faker->text(500);

        return [
            'document_id' => Document::factory(),
            'chunking_strategy' => ChunkingStrategy::Tokens500,
            'chunk_index' => $this->faker->unique()->numberBetween(0, 100000),
            'content' => $content,
            'token_count' => str_word_count($content),
            'embedding' => $this->randomEmbedding(),
        ];
    }

    /**
     * @return array<int, float>
     */
    private function randomEmbedding(): array
    {
        return array_map(
            fn () => $this->faker->randomFloat(6, -1, 1),
            range(1, 384),
        );
    }
}
