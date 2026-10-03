<?php

namespace App\Salla\Handlers;

use App\Billing\WalletService;
use App\Enums\MerchantStatus;
use App\Jobs\FetchMerchantProfile;
use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Salla\PayloadVault;
use App\Services\MerchantService;
use App\Services\TokenService;
use App\Sync\InitialProductSync;

class StoreAuthorizeHandler implements AppEventHandler
{
    public function __construct(
        private MerchantService $merchants,
        private TokenService $tokens,
        private PayloadVault $vault,
        private WalletService $wallets,
    ) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);

        if ($this->merchants->isStale($merchant, $event)) {
            return HandlerResult::Ignored;
        }

        $this->tokens->store($merchant, $this->vault->reveal($event->data()));

        $needsSync = $merchant->sync_finished_at === null
            || in_array($merchant->status, [MerchantStatus::Pending, MerchantStatus::Uninstalled, MerchantStatus::Purged], true);
        $isReinstall = $merchant->uninstalled_at !== null
            || in_array($merchant->status, [MerchantStatus::Uninstalled, MerchantStatus::Purged], true);

        $attributes = [
            'uninstalled_at' => null,
            'purge_at' => null,
            'reauth_required' => false,
            'last_event_at' => $event->event_created_at,
        ];

        if ($needsSync) {
            $attributes += [
                'status' => MerchantStatus::Syncing,
                'sync_pages_done' => 0,
                'sync_total_pages' => null,
                'sync_started_at' => now(),
                'sync_finished_at' => null,
            ];
        } else {
            $attributes['status'] = $merchant->status === MerchantStatus::Inactive ? MerchantStatus::Active : $merchant->status;
        }

        $merchant->forceFill($attributes)->save();

        $this->wallets->grantStarter($merchant->merchant_id);

        FetchMerchantProfile::dispatch($merchant->merchant_id)
            ->onQueue(config('salla.queue'))
            ->afterCommit();

        if ($needsSync) {
            InitialProductSync::dispatch($merchant->merchant_id, $isReinstall)
                ->onQueue(config('salla.queue'))
                ->afterCommit();
        }

        return HandlerResult::Processed;
    }
}
