<?php

namespace Database\Factories;

use App\Models\CreditReservation;
use App\Models\MerchantWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditReservation>
 */
class CreditReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wallet_id' => fn (): int => MerchantWallet::factory()->create()->id,
            'merchant_id' => fn (array $attributes): int => MerchantWallet::find($attributes['wallet_id'])->merchant_id,
            'amount' => 20,
            'status' => CreditReservation::ACTIVE,
            'action' => 'image_edit',
            'price_source' => 'default',
        ];
    }
}
