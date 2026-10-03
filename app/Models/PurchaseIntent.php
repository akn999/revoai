<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\PurchaseIntentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $wallet_id
 * @property int $merchant_id
 * @property int|null $pack_id
 * @property int $credits
 * @property string $status
 * @property string|null $salla_order_id
 * @property string|null $verifier_strategy
 * @property array<string, mixed>|null $result
 * @property Carbon|null $reconciled_at
 * @property Carbon|null $reversed_at
 * @property int $reversal_shortfall
 * @property Carbon $expires_at
 */
#[UseFactory(PurchaseIntentFactory::class)]
class PurchaseIntent extends Model
{
    /** @use HasFactory<PurchaseIntentFactory> */
    use AuditsAdminChanges, HasFactory;

    public const CREATED = 'created';

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REVERSED = 'reversed';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'credits' => 'integer',
            'result' => 'array',
            'reconciled_at' => 'datetime',
            'reversed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CreditPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(CreditPack::class, 'pack_id');
    }

    /**
     * @return BelongsTo<MerchantWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::CREATED, self::PENDING], true) && $this->expires_at->isFuture();
    }
}
