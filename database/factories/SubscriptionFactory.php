<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Merchant;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'salla_subscription_id' => fake()->unique()->numberBetween(1000000000, 1999999999),
            'item_type' => 'plan',
            'item_key' => 'plan',
            'plan_type' => 'recurring',
            'billing_cycle' => BillingCycle::Monthly,
            'period_months' => 1,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'price' => 20,
            'total' => 23,
            'last_event_at' => now(),

        ];
    }

    public function addon(string $slug = 'addon_chat_support'): static
    {
        return $this->state(['item_type' => 'addon', 'item_key' => $slug, 'billing_cycle' => BillingCycle::OneTime, 'ends_at' => null]);
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
