<?php

namespace App\Models;

use Database\Factories\CreditReservationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $wallet_id
 * @property int $merchant_id
 * @property int $amount
 * @property string $status
 * @property string $action
 * @property string $price_source
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
#[UseFactory(CreditReservationFactory::class)]
class CreditReservation extends Model
{
    /** @use HasFactory<CreditReservationFactory> */
    use HasFactory;

    public const ACTIVE = 'active';

    public const CAPTURED = 'captured';

    public const RELEASED = 'released';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'merchant_id' => 'integer', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MerchantWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
