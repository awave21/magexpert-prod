<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use App\Models\VideoProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProgress>
 */
class VideoProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'video_key' => fake()->regexify('[A-Za-z0-9]{22}'),
            'position' => fake()->numberBetween(30, 1800),
            'duration' => 3600,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['position' => 3500, 'duration' => 3600, 'completed_at' => now()]);
    }
}
