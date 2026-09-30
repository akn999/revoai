<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\MerchantStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AppEvent;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Salla\HandlerResult;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

class SubscriptionService
{
    public function start(Merchant $merchant, AppEvent $event): HandlerResult
    {
        $data = $event->data();

        if (empty($data['subscription_id'])) {
            return HandlerResult::Ignored;
        }

        $itemType = $this->itemType($data);
        $itemKey = $this->itemKey($data);
        $start = $this->date($data['start_date'] ?? null);
        $end = $this->date($data['end_date'] ?? null);
        [$cycle, $months] = BillingCycle::fromPayload($data['plan_type'] ?? null, $start, $end);
        $plan = Plan::matchPayload($itemType, $data['plan_name'] ?? null, $data['item_slug'] ?? null);

        $subscription = $this->find($merchant, $event);

        if ($subscription && ($subscription->isStale($event->event_created_at) || $subscription->status === SubscriptionStatus::Superseded)) {
            return HandlerResult::Ignored;
        }

        $status = SubscriptionStatus::Active;

        if ($itemType === 'plan') {
            if ($this->hasNewerPlan($merchant, $event, $subscription?->id)) {
                $status = SubscriptionStatus::Superseded;
            } else {
                $this->supersedeOpenPlans($merchant, $event, $subscription?->id);
            }
        }

        $from = $subscription ? $subscription->snapshot() : null;

        $subscription ??= new Subscription([
            'merchant_id' => $merchant->merchant_id,
            'salla_subscription_id' => $data['subscription_id'],
            'item_key' => $itemKey,
        ]);

        $subscription->fill([
            'plan_id' => $plan?->id,
            'item_type' => $itemType,
            'plan_name' => $data['plan_name'] ?? null,
            'plan_type' => $data['plan_type'] ?? null,
            'billing_cycle' => $cycle,
            'period_months' => $months,
            'plan_period' => isset($data['plan_period']) ? (string) $data['plan_period'] : null,
            'quantity' => $data['quantity'] ?? 1,
            'status' => $status,
            'superseded_at' => $status === SubscriptionStatus::Superseded ? ($event->event_created_at ?? now()) : null,
            'starts_at' => $start,
            'ends_at' => $end,
            ...$this->chargeAttributes($data),
            'store_type' => $data['store_type'] ?? null,
            'meta' => Arr::only($data, ['promotion', 'subscription_balance', 'categories']),
            'last_event_at' => $event->event_created_at,
        ])->save();

        $this->addPeriod($subscription, 'start', $data, $event);
        $this->syncFeatures($subscription, $data['features'] ?? null);

        if ($status === SubscriptionStatus::Superseded) {
            $subscription->recordChange('superseded', $from ?? [], $event);

            return HandlerResult::Processed;
        }

        $subscription->recordChange($this->changeType($from, $subscription), $from ?? [], $event);
        $merchant->activateFrom($event);

        return HandlerResult::Processed;
    }

    public function renew(Merchant $merchant, AppEvent $event): HandlerResult
    {
        $subscription = $this->find($merchant, $event);

        if (! $subscription) {
            return $this->start($merchant, $event);
        }

        if ($subscription->isStale($event->event_created_at) || $subscription->status === SubscriptionStatus::Superseded) {
            return HandlerResult::Ignored;
        }

        $data = $event->data();
        $from = $subscription->snapshot();

        if ($subscription->item_type === 'plan') {
            $this->supersedeOpenPlans($merchant, $event, $subscription->id);
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'ends_at' => $this->date($data['end_date'] ?? null),
            'renewed_at' => $this->date($data['renew_date'] ?? null) ?? $event->event_created_at,
            'expired_at' => null,
            'canceled_at' => null,
            ...$this->chargeAttributes($data),
            'last_event_at' => $event->event_created_at,
        ]);

        $this->addPeriod($subscription, 'renewal', $data, $event);
        $this->syncFeatures($subscription, $data['features'] ?? null);
        $subscription->recordChange('renewed', $from, $event);
        $merchant->activateFrom($event);

        return HandlerResult::Processed;
    }

    public function end(Merchant $merchant, AppEvent $event, SubscriptionStatus $to): HandlerResult
    {
        $subscription = $this->find($merchant, $event);

        if (! $subscription
            || $subscription->isStale($event->event_created_at)
            || $subscription->status === SubscriptionStatus::Superseded) {
            return HandlerResult::Ignored;
        }

        $this->close($subscription, $to, $event);
        $this->refreshMerchantStatus($merchant);

        return HandlerResult::Processed;
    }

    public function startTrial(Merchant $merchant, AppEvent $event): HandlerResult
    {
        $openPlans = $merchant->subscriptions()->where('item_type', 'plan')
            ->whereIn('status', ['trial', 'active'])->lockForUpdate()->get();

        if ($openPlans->contains(fn (Subscription $plan) => $plan->status !== SubscriptionStatus::Trial)) {
            return HandlerResult::Ignored;
        }

        $trial = $openPlans->first();

        if ($trial?->isStale($event->event_created_at)) {
            return HandlerResult::Ignored;
        }

        $data = $event->data();
        $from = $trial?->snapshot();
        $plan = Plan::matchPayload('plan', $data['plan_name'] ?? null, null);

        $trial ??= new Subscription([
            'merchant_id' => $merchant->merchant_id,
            'item_type' => 'plan',
            'item_key' => 'plan',
        ]);

        $trial->fill([
            'plan_id' => $plan?->id,
            'plan_name' => $data['plan_name'] ?? null,
            'plan_type' => $data['plan_type'] ?? null,
            'billing_cycle' => BillingCycle::Trial,
            'status' => SubscriptionStatus::Trial,
            'starts_at' => $this->date($data['start_date'] ?? null),
            'ends_at' => $this->date($data['end_date'] ?? null),
            'store_type' => $data['store_type'] ?? null,
            'meta' => Arr::only($data, ['categories']),
            'last_event_at' => $event->event_created_at,
        ])->save();

        $this->addPeriod($trial, 'trial', $data, $event);
        $this->syncFeatures($trial, $data['features'] ?? null);
        $trial->recordChange('started', $from ?? [], $event);
        $merchant->activateFrom($event);

        return HandlerResult::Processed;
    }

    public function endTrial(Merchant $merchant, AppEvent $event, SubscriptionStatus $to): HandlerResult
    {
        $trial = $merchant->subscriptions()->where('item_type', 'plan')
            ->where('status', SubscriptionStatus::Trial->value)->lockForUpdate()->first();

        if (! $trial || $trial->isStale($event->event_created_at)) {
            return HandlerResult::Ignored;
        }

        $this->close($trial, $to, $event, endAccessNow: true);
        $this->refreshMerchantStatus($merchant);

        return HandlerResult::Processed;
    }

    /**
     * Close every subscription that still grants access, as part of an uninstall.
     */
    public function closeAllOpen(Merchant $merchant, AppEvent $event): void
    {
        $merchant->subscriptions()->entitled()->lockForUpdate()->get()
            ->each(fn (Subscription $subscription) => $this->close($subscription, SubscriptionStatus::Canceled, $event, endAccessNow: true));
    }

    /**
     * Merchant becomes inactive once nothing grants access.
     */
    public function refreshMerchantStatus(Merchant $merchant): void
    {
        if ($merchant->status === MerchantStatus::Active && ! $merchant->subscriptions()->entitled()->exists()) {
            $merchant->update(['status' => MerchantStatus::Inactive]);
        }
    }

    /**
     * Safety net for lost webhooks: expire rows whose period has ended.
     *
     * @return int Number of subscriptions expired.
     */
    public function sweepExpired(): int
    {
        $expired = 0;

        Subscription::withoutGlobalScopes()
            ->whereIn('status', ['trial', 'active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->chunkById(200, function ($subscriptions) use (&$expired): void {
                foreach ($subscriptions as $subscription) {
                    $from = $subscription->snapshot();

                    $subscription->update(['status' => SubscriptionStatus::Expired, 'expired_at' => now()]);
                    $subscription->recordChange('expired', $from);
                    $expired++;

                    if ($merchant = Merchant::findBySallaId($subscription->merchant_id)) {
                        $this->refreshMerchantStatus($merchant);
                    }
                }
            });

        return $expired;
    }

    private function close(Subscription $subscription, SubscriptionStatus $to, AppEvent $event, bool $endAccessNow = false): void
    {
        $from = $subscription->snapshot();
        $at = $event->event_created_at ?? now();
        $accessEndsAt = $at->gt(now()) ? now() : $at;

        $attributes = [
            'status' => $to,
            ($to === SubscriptionStatus::Expired ? 'expired_at' : 'canceled_at') => $at,
            'last_event_at' => $event->event_created_at ?? $subscription->last_event_at,
        ];

        if ($endAccessNow && (! $subscription->ends_at || $subscription->ends_at->gt($accessEndsAt))) {
            $attributes['ends_at'] = $accessEndsAt;
        }

        $subscription->update($attributes);
        $subscription->recordChange($to->value, $from, $event);
    }

    /**
     * Supersede every plan row that still grants access, except the one being written.
     */
    private function supersedeOpenPlans(Merchant $merchant, AppEvent $event, ?int $exceptId): void
    {
        $merchant->subscriptions()->where('item_type', 'plan')->entitled()
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->lockForUpdate()->get()
            ->each(function (Subscription $old) use ($event): void {
                $from = $old->snapshot();

                $old->update([
                    'status' => SubscriptionStatus::Superseded,
                    'superseded_at' => $event->event_created_at ?? now(),
                ]);
                $old->recordChange('superseded', $from, $event);
            });
    }

    /**
     * A plan row that was written by a newer event means this (older) started event only becomes history.
     */
    private function hasNewerPlan(Merchant $merchant, AppEvent $event, ?int $exceptId): bool
    {
        if (! $event->event_created_at) {
            return false;
        }

        return $merchant->subscriptions()->where('item_type', 'plan')->entitled()
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->where('last_event_at', '>', $event->event_created_at)
            ->exists();
    }

    /**
     * @param  array{status: string, billing_cycle: string, plan_id: int|null, quantity: int}|null  $from
     */
    private function changeType(?array $from, Subscription $subscription): string
    {
        return match (true) {
            $from === null => 'started',
            $from['billing_cycle'] !== $subscription->billing_cycle->value => 'cycle_changed',
            $from['plan_id'] !== $subscription->plan_id => 'plan_changed',
            $from['quantity'] !== (int) $subscription->quantity => 'quantity_changed',
            default => 'started',
        };
    }

    private function find(Merchant $merchant, AppEvent $event): ?Subscription
    {
        $data = $event->data();

        if (empty($data['subscription_id'])) {
            return null;
        }

        return Subscription::where('merchant_id', $merchant->merchant_id)
            ->where('salla_subscription_id', $data['subscription_id'])
            ->where('item_key', $this->itemKey($data))
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function itemType(array $data): string
    {
        return ($data['item_type'] ?? 'plan') === 'addon' ? 'addon' : 'plan';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function itemKey(array $data): string
    {
        return $this->itemType($data) === 'addon' ? ($data['item_slug'] ?? 'addon') : 'plan';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function chargeAttributes(array $data): array
    {
        return [
            'price' => $data['price'] ?? null,
            'price_before_discount' => $data['price_before_discount'] ?? null,
            'initialization_cost' => $data['initialization_cost'] ?? null,
            'tax_rate' => $data['tax'] ?? null,
            'tax_value' => $data['tax_value'] ?? null,
            'total' => $data['total'] ?? null,
            'coupon_code' => data_get($data, 'coupon.name'),
            'coupon_amount' => data_get($data, 'coupon.amount'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addPeriod(Subscription $subscription, string $kind, array $data, AppEvent $event): void
    {
        $startsAt = $kind === 'renewal'
            ? ($this->date($data['renew_date'] ?? null) ?? $event->event_created_at)
            : $this->date($data['start_date'] ?? null);

        $subscription->periods()->updateOrCreate(
            ['kind' => $kind, 'starts_at' => $startsAt],
            [
                'ends_at' => $this->date($data['end_date'] ?? null),
                'renew_date' => $this->date($data['renew_date'] ?? null),
                'price' => $data['price'] ?? null,
                'tax_value' => $data['tax_value'] ?? null,
                'total' => $data['total'] ?? null,
                'coupon_code' => data_get($data, 'coupon.name'),
                'app_event_id' => $event->id,
            ],
        );
    }

    /**
     * Replace feature rows from the payload; when the payload has no features key, copy the matched plan's defaults.
     *
     * @param  array<int, array{key?: string, quantity?: int}>|null  $features
     */
    private function syncFeatures(Subscription $subscription, ?array $features): void
    {
        if ($features === null) {
            $subscription->loadMissing('plan.features');

            if (! $subscription->plan) {
                return;
            }

            $features = $subscription->plan->features
                ->map(fn ($feature) => ['key' => $feature->feature_key, 'quantity' => $feature->quantity])
                ->all();
        }

        $subscription->features()->delete();

        foreach ($features as $feature) {
            if (empty($feature['key'])) {
                continue;
            }

            $subscription->features()->updateOrCreate(
                ['feature_key' => $feature['key']],
                ['quantity' => $feature['quantity'] ?? 1],
            );
        }
    }

    private function date(?string $value): ?CarbonInterface
    {
        return $value ? Date::parse($value)->timezone(config('app.timezone')) : null;
    }
}
