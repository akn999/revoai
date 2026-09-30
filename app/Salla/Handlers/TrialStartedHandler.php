<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Services\SubscriptionService;

class TrialStartedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private SubscriptionService $subscriptions) {}

    public function handle(AppEvent $event): HandlerResult
    {
        return $this->subscriptions->startTrial($this->merchants->resolve($event), $event);
    }
}
