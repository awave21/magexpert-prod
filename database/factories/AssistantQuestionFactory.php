<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AssistantQuestion>
 */
class AssistantQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'question' => fake()->sentence().'?',
            'found' => true,
            'sources' => [],
            'model' => 'gpt-6-luna',
            'input_tokens' => 4000,
            'output_tokens' => 400,
            'duration_ms' => 4200,
        ];
    }

    public function unanswered(): static
    {
        return $this->state(['found' => false, 'sources' => [], 'input_tokens' => 0, 'output_tokens' => 0]);
    }
}
