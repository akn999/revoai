<?php

namespace App\Services;

use App\Enums\MerchantStatus;
use App\Models\AppEvent;
use App\Models\Merchant;

class MerchantService
{
    private const STORE_TYPES = ['development', 'demo', 'live'];

    /**
     * Find or create the merchant an event belongs to, restoring it if it was soft-deleted.
     */
    public function resolve(AppEvent $event): Merchant
    {
        $merchant = Merchant::withTrashed()->createOrFirst(
            ['merchant_id' => $event->merchant_id],
            ['status' => MerchantStatus::Pending, 'default_language' => 'ar', 'enabled_languages' => config('revo.product.languages')],
        );

        if ($merchant->trashed()) {
            $merchant->restore();
        }

        $storeType = $event->data('store_type');

        if (in_array($storeType, self::STORE_TYPES, true) && $merchant->store_type !== $storeType) {
            $merchant->forceFill(['store_type' => $storeType])->save();
        }

        return $merchant;
    }

    /**
     * True when the event is older than the newest lifecycle event already applied (FR-8).
     */
    public function isStale(Merchant $merchant, AppEvent $event): bool
    {
        return $merchant->last_event_at !== null
            && $event->event_created_at !== null
            && $event->event_created_at->lt($merchant->last_event_at);
    }

    /**
     * @param  array<string, mixed>  $info  The `data` object of the Salla user-info response.
     */
    public function syncProfile(Merchant $merchant, array $info): void
    {
        $store = $info['merchant'] ?? [];

        $merchant->forceFill([
            'name' => $store['name'] ?? $merchant->name,
            'domain' => $store['domain'] ?? $merchant->domain,
            'avatar' => $store['avatar'] ?? $merchant->avatar,
            'owner_name' => $info['name'] ?? null,
            'owner_email' => $info['email'] ?? null,
            'email' => $info['email'] ?? $merchant->email,
            'mobile' => $info['mobile'] ?? $merchant->mobile,
            'profile_synced_at' => now(),
        ])->save();
    }
}
