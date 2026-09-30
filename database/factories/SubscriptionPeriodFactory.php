<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPeriod>
 */
class SubscriptionPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'kind' => 'start',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),

        ];
    }
}
