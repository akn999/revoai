<?php

namespace App\Models\Concerns;

use App\Models\Merchant;
use App\Support\CurrentMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $merchant_id
 */
trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope('merchant', function (Builder $query): void {
            if ($merchantId = app(CurrentMerchant::class)->id()) {
                $query->where($query->getModel()->qualifyColumn('merchant_id'), $merchantId);
            }
        });

        static::creating(function (self $model): void {
            $model->merchant_id ??= app(CurrentMerchant::class)->id();
        });
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'merchant_id');
    }
}
