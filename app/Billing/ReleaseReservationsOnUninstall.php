<?php

namespace App\Billing;

use App\Platform\Events\StoreUninstalled;

class ReleaseReservationsOnUninstall
{
    public function __construct(private WalletService $wallets) {}

    public function handle(StoreUninstalled $event): void
    {
        $this->wallets->releaseAllFor($event->merchantId, 'Released: the app was uninstalled');
    }
}
