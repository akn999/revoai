<?php

namespace Database\Factories;

use App\Models\MerchantWallet;
use App\Models\PurchaseIntent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseIntent>
 */
class PurchaseIntentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'wallet_id' => fn (): int => MerchantWallet::factory()->create()->id,
            'merchant_id' => fn (array $attributes): int => MerchantWallet::find($attributes['wallet_id'])->merchant_id,
            'credits' => 500,
            'status' => PurchaseIntent::CREATED,
            'expires_at' => now()->addDay(),
        ];
    }
}
