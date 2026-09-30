<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionChange>
 */
class SubscriptionChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'change_type' => 'started',
            'to_status' => 'active',
            'occurred_at' => now(),

        ];
    }
}
