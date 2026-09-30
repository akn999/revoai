<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;

class SettingsUpdatedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);
        $current = $merchant->settings()->with('sourceEvent')->first();
        $currentEventAt = $current?->sourceEvent?->event_created_at;

        if ($currentEventAt && $event->event_created_at && $event->event_created_at->lt($currentEventAt)) {
            return HandlerResult::Ignored;
        }

        $merchant->settings()->updateOrCreate([], [
            'settings' => $event->data('settings', []),
            'updated_from_event_id' => $event->id,
        ]);

        return HandlerResult::Processed;
    }
}
