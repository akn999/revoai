<?php

namespace App\Models;

use Database\Factories\MerchantWalletFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $balance
 * @property int $reserved
 * @property Carbon|null $starter_granted_at
 */
#[UseFactory(MerchantWalletFactory::class)]
class MerchantWallet extends Model
{
    /** @use HasFactory<MerchantWalletFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'balance' => 'integer',
            'reserved' => 'integer',
            'starter_granted_at' => 'datetime',
        ];
    }

    /**
     * Credits that can be spent right now: the balance minus active reservations.
     */
    public function available(): int
    {
        return max(0, $this->balance - $this->reserved);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'wallet_id');
    }

    /**
     * @return HasMany<CreditReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(CreditReservation::class, 'wallet_id');
    }
}
