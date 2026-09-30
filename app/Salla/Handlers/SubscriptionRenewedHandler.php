<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Services\SubscriptionService;

class SubscriptionRenewedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private SubscriptionService $subscriptions) {}

    public function handle(AppEvent $event): HandlerResult
    {
        return $this->subscriptions->renew($this->merchants->resolve($event), $event);
    }
}
