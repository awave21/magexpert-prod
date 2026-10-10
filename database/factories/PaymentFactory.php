<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory()->paid(),
            'amount' => 5000,
            'currency' => 'RUB',
            'status' => Payment::STATUS_COMPLETED,
            'payment_system' => 'test',
            'paid_at' => now(),
        ];
    }
}
