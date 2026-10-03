<?php

namespace App\Salla\Handlers;

use App\Enums\MerchantStatus;
use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use Illuminate\Support\Facades\Date;

class AppInstalledHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);

        if ($this->merchants->isStale($merchant, $event)) {
            return HandlerResult::Ignored;
        }

        $installedAt = $event->data('installation_date');

        $merchant->forceFill([
            'installed_at' => $installedAt ? Date::parse($installedAt) : $event->event_created_at,
            'app_scopes' => $event->data('app_scopes', $merchant->app_scopes),
            'last_event_at' => $event->event_created_at,
        ])->save();

        if (in_array($merchant->status, [MerchantStatus::Uninstalled, MerchantStatus::Purged], true)) {
            $merchant->forceFill(['status' => MerchantStatus::Pending])->save();
        }

        return HandlerResult::Processed;
    }
}
