<?php

namespace Database\Factories;

use App\Enums\AppEventStatus;
use App\Models\AppEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppEvent>
 */
class AppEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fake()->numberBetween(100000000, 999999999),
            'event' => 'app.installed',
            'payload_hash' => fake()->unique()->sha256(),
            'payload' => ['event' => 'app.installed', 'data' => []],
            'event_created_at' => now(),
            'status' => AppEventStatus::Received,

        ];
    }
}
