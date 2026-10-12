<?php

namespace Database\Factories;

use App\Models\MedicalLibrary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\LibraryChunk>
 */
class LibraryChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'medical_library_id' => MedicalLibrary::factory(),
            'position' => fake()->unique()->numberBetween(0, 100000),
            'type' => 'text',
            'page_start' => 1,
            'page_end' => 1,
            'headings' => ['Введение'],
            'content' => $content,
            'embed_text' => "Введение\n".$content,
            'content_sha256' => hash('sha256', $content),
            'tokens' => 120,
            'embedding' => array_fill(0, 1536, 0.01),
        ];
    }

    public function table(): static
    {
        return $this->state(['type' => 'table']);
    }
}
