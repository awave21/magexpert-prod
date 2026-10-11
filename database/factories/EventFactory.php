<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'start_date' => now()->addDays(7)->toDateString(),
            'start_time' => '10:00:00',
            'event_type' => 'webinar',
            'format' => 'online',
            'is_active' => true,
            'is_archived' => false,
            'is_paid' => false,
            'show_price' => true,
            'registration_enabled' => true,
        ];
    }

    public function paid(int $price = 5000): static
    {
        return $this->state(fn (): array => ['is_paid' => true, 'price' => $price]);
    }

    public function past(): static
    {
        return $this->state(fn (): array => ['start_date' => now()->subDays(10)->toDateString(), 'is_archived' => true]);
    }
}
