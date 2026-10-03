<?php

namespace App\Salla\Handlers;

use App\Enums\MerchantStatus;
use App\Models\AppEvent;
use App\Platform\Events\StoreUninstalled;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Date;

class AppUninstalledHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private SubscriptionService $subscriptions) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);

        if ($this->merchants->isStale($merchant, $event)) {
            return HandlerResult::Ignored;
        }

        $merchant->token()->delete();
        $this->subscriptions->closeAllOpen($merchant, $event);

        $uninstalledAt = $event->data('uninstallation_date');

        $merchant->forceFill([
            'status' => MerchantStatus::Uninstalled,
            'uninstalled_at' => $uninstalledAt ? Date::parse($uninstalledAt) : $event->event_created_at,
            'purge_at' => now()->addDays((int) config('revo.limits.purge_grace_days')),
            'last_event_at' => $event->event_created_at,
        ])->save();

        StoreUninstalled::dispatch($merchant->merchant_id);

        return HandlerResult::Processed;
    }
}
