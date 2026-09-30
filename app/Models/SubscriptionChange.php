<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\SubscriptionChangeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $merchant_id
 * @property string $change_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $from_billing_cycle
 * @property string|null $to_billing_cycle
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property int|null $app_event_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
#[UseFactory(SubscriptionChangeFactory::class)]
class SubscriptionChange extends Model
{
    /** @use HasFactory<SubscriptionChangeFactory> */
    use BelongsToMerchant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class)->withoutGlobalScopes();
    }
}
