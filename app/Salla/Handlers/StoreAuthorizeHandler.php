<?php

namespace App\Salla\Handlers;

use App\Jobs\FetchMerchantProfile;
use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Salla\PayloadVault;
use App\Services\MerchantService;
use App\Services\TokenService;

class StoreAuthorizeHandler implements AppEventHandler
{
    public function __construct(
        private MerchantService $merchants,
        private TokenService $tokens,
        private PayloadVault $vault,
    ) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);

        if ($this->merchants->isStale($merchant, $event)) {
            return HandlerResult::Ignored;
        }

        $this->tokens->store($merchant, $this->vault->reveal($event->data()));
        $merchant->activate();
        $merchant->forceFill(['last_event_at' => $event->event_created_at])->save();

        FetchMerchantProfile::dispatch($merchant->merchant_id)
            ->onQueue(config('salla.queue'))
            ->afterCommit();

        return HandlerResult::Processed;
    }
}
