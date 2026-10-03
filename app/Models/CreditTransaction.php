<?php

namespace App\Models;

use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only ledger row. Sum of `amount` always equals the wallet balance;
 * sum of `reserved_delta` always equals the wallet's reserved credits.
 *
 * @property int $id
 * @property int $wallet_id
 * @property int $merchant_id
 * @property string $type
 * @property int $amount
 * @property int $balance_after
 * @property int $reserved_delta
 * @property int $reserved_after
 * @property int|null $reservation_id
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $reason
 * @property string|null $actor
 * @property Carbon $created_at
 */
#[UseFactory(CreditTransactionFactory::class)]
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STARTER_GRANT = 'starter_grant';

    public const PURCHASE = 'purchase';

    public const MANUAL_GRANT = 'manual_grant';

    public const MANUAL_DEDUCT = 'manual_deduct';

    public const RESERVE = 'reserve';

    public const CAPTURE = 'capture';

    public const RELEASE = 'release';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Credit transactions are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Credit transactions are append-only.');
        });
    }

    /**
     * @return BelongsTo<MerchantWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }
}
