<?php

namespace Database\Factories;

use App\Models\Query;
use App\Models\RagasEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RagasEvaluation>
 */
class RagasEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_id' => Query::factory(),
            'context_precision' => $this->faker->randomFloat(4, 0, 1),
            'context_recall' => $this->faker->randomFloat(4, 0, 1),
            'faithfulness' => $this->faker->randomFloat(4, 0, 1),
            'answer_relevance' => $this->faker->randomFloat(4, 0, 1),
            'judge_model' => 'gpt-4o',
            'raw_judge_response' => ['reasoning' => $this->faker->sentence()],
        ];
    }
}
