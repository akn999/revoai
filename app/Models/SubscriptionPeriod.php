<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\SubscriptionPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $subscription_id
 * @property string $kind
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $renew_date
 * @property string|null $price
 * @property string|null $tax_value
 * @property string|null $total
 * @property string|null $coupon_code
 * @property int|null $app_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(SubscriptionPeriodFactory::class)]
class SubscriptionPeriod extends Model
{
    /** @use HasFactory<SubscriptionPeriodFactory> */
    use HasFactory, LogsActivity;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'renew_date' => 'datetime'];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class)->withoutGlobalScopes();
    }
}
