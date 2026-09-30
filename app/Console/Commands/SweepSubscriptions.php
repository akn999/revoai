<?php

namespace App\Console\Commands;

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
        $this->info("Expired {$subscriptions->sweepExpired()} subscription(s).");

        return self::SUCCESS;
    }
}
