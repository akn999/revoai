<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToMerchant;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $plan_id
 * @property int|null $salla_subscription_id
 * @property string $item_type
 * @property string $item_key
 * @property string|null $plan_name
 * @property string|null $plan_type
 * @property BillingCycle $billing_cycle
 * @property int|null $period_months
 * @property string|null $plan_period
 * @property int $quantity
 * @property SubscriptionStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $renewed_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $superseded_at
 * @property string|null $price
 * @property string|null $price_before_discount
 * @property string|null $initialization_cost
 * @property string|null $tax_rate
 * @property string|null $tax_value
 * @property string|null $total
 * @property string $currency
 * @property string|null $coupon_code
 * @property string|null $coupon_amount
 * @property string|null $store_type
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $last_event_at
 * @property int|null $current_plan_lock
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(SubscriptionFactory::class)]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToMerchant, HasFactory, LogsActivity;

    protected $guarded = ['current_plan_lock'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'meta' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'renewed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'expired_at' => 'datetime',
            'superseded_at' => 'datetime',
            'last_event_at' => 'datetime',
            'price' => 'decimal:2',
            'price_before_discount' => 'decimal:2',
            'initialization_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SubscriptionPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class);
    }

    /**
     * @return HasMany<SubscriptionFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(SubscriptionFeature::class);
    }

    /**
     * @return HasMany<SubscriptionChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
    }

    /**
     * Rows that currently grant access.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeEntitled(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereIn('status', ['trial', 'active'])
            ->orWhere(fn (Builder $query) => $query->where('status', 'canceled')->where('ends_at', '>', now())));
    }

    public function isStale(?CarbonInterface $eventAt): bool
    {
        return $eventAt && $this->last_event_at && $eventAt->lt($this->last_event_at);
    }

    /**
     * @param  array{status?: string|null, billing_cycle?: string|null, plan_id?: int|null}  $from
     */
    public function recordChange(string $type, array $from, ?AppEvent $event = null): void
    {
        $attributes = [
            'merchant_id' => $this->merchant_id,
            'change_type' => $type,
            'app_event_id' => $event?->id,
        ];

        $values = [
            'from_status' => $from['status'] ?? null,
            'to_status' => $this->status->value,
            'from_billing_cycle' => $from['billing_cycle'] ?? null,
            'to_billing_cycle' => $this->billing_cycle->value,
            'from_plan_id' => $from['plan_id'] ?? null,
            'to_plan_id' => $this->plan_id,
            'occurred_at' => $event->event_created_at ?? now(),
        ];

        if ($event) {
            $this->changes()->firstOrCreate($attributes, $values);

            return;
        }

        $this->changes()->create($attributes + $values);
    }

    /**
     * @return array{status: string, billing_cycle: string, plan_id: int|null, quantity: int}
     */
    public function snapshot(): array
    {
        return [
            'status' => $this->status->value,
            'billing_cycle' => $this->billing_cycle->value,
            'plan_id' => $this->plan_id,
            'quantity' => (int) $this->quantity,
        ];
    }
}
