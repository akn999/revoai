<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Logging\Activity;
use App\Models\Merchant;

/**
 * Keeps the merchant's Revo plan in step with its Salla subscriptions (FR-INS-008).
 * An unmapped Salla plan keeps the previous plan and raises an alert instead of locking the store.
 */
class MerchantPlanService
{
    public function sync(Merchant $merchant): void
    {
        $current = $merchant->subscriptions()
            ->where('item_type', 'plan')
            ->entitled()
            ->with('plan')
            ->orderByDesc('id')
            ->first();

        if ($current === null) {
            $merchant->forceFill(['plan_status' => $merchant->plan_code ? 'lapsed' : null])->save();

            return;
        }

        if ($current->plan === null) {
            Activity::channel('system')->bySystem()->forMerchant($merchant->merchant_id)->on($current)
                ->with(['salla_plan_name' => $current->plan_name])
                ->warning('plan.unmapped', 'A Salla plan has no Revo plan mapping; the previous plan is kept');

            $merchant->forceFill(['salla_plan_ref' => $current->plan_name])->save();

            return;
        }

        $merchant->forceFill([
            'plan_code' => $current->plan->slug,
            'plan_status' => $current->status === SubscriptionStatus::Trial ? 'trial' : 'active',
            'salla_plan_ref' => $current->plan_name,
        ])->save();
    }
}
