<?php

namespace App\Salla\Handlers;

use App\Enums\SubscriptionStatus;
use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Services\SubscriptionService;

class SubscriptionEndedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private SubscriptionService $subscriptions) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $to = $event->event === 'app.subscription.expired' ? SubscriptionStatus::Expired : SubscriptionStatus::Canceled;

        return $this->subscriptions->end($this->merchants->resolve($event), $event, $to);
    }
}
