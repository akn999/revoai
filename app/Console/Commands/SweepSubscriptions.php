<?php

namespace App\Console\Commands;

use App\Logging\Activity;
use App\Services\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('salla:subscriptions:sweep')]
#[Description('Expire subscriptions whose period ended without an expired webhook')]
class SweepSubscriptions extends Command
{
    public function handle(SubscriptionService $subscriptions): int
    {
        $expired = $subscriptions->sweepExpired();
        $this->info("Expired {$expired} subscription(s).");

        Activity::channel('system')->bySystem()->with(['expired' => $expired])
            ->info('salla.subscriptions_sweep_run', "Subscription sweep expired {$expired} subscription(s)");

        return self::SUCCESS;
    }
}
