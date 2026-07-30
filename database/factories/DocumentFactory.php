<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = $this->faker->slug().'.pdf';

        return [
            'title' => $this->faker->sentence(4),
            'original_filename' => $filename,
            'disk_path' => 'documents/'.$filename,
            'page_count' => $this->faker->numberBetween(1, 200),
            'status' => DocumentStatus::Ready,
            'error_message' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => DocumentStatus::Pending]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => DocumentStatus::Failed,
            'error_message' => $this->faker->sentence(),
        ]);
    }
}
