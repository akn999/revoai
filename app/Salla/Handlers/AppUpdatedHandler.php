<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use Illuminate\Support\Facades\Date;

class AppUpdatedHandler implements AppEventHandler
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
            'app_scopes' => $event->data('app_scopes', $merchant->app_scopes),
            'installed_at' => $merchant->installed_at ?? ($installedAt ? Date::parse($installedAt) : null),
            'last_event_at' => $event->event_created_at,
        ])->save();

        return HandlerResult::Processed;
    }
}
