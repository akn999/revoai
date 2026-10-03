<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\MerchantWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wallet_id' => fn (): int => MerchantWallet::factory()->create()->id,
            'merchant_id' => fn (array $attributes): int => MerchantWallet::find($attributes['wallet_id'])->merchant_id,
            'type' => CreditTransaction::MANUAL_GRANT,
            'amount' => 10,
            'balance_after' => 10,
        ];
    }
}
