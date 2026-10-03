<?php

namespace App\Console\Commands;

use App\Billing\WalletService;
use App\Logging\Activity;
use App\Models\PurchaseIntent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('revo:billing:sweep')]
#[Description('Release stale credit reservations and expire unused purchase intents')]
class SweepReservations extends Command
{
    public function handle(WalletService $wallets): int
    {
        $released = $wallets->sweepStale();

        $expired = PurchaseIntent::query()
            ->whereIn('status', [PurchaseIntent::CREATED, PurchaseIntent::PENDING])
            ->where('expires_at', '<', now())
            ->update(['status' => PurchaseIntent::EXPIRED]);

        $this->info("Released {$released} reservation(s), expired {$expired} intent(s).");

        if ($released > 0 || $expired > 0) {
            Activity::channel('system')->bySystem()->with(compact('released', 'expired'))
                ->info('billing.sweep', "Billing sweep released {$released} reservation(s) and expired {$expired} intent(s)");
        }

        return self::SUCCESS;
    }
}
