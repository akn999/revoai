<?php

namespace App\Platform\Listeners;

use App\Platform\EmbeddedSessionService;
use App\Platform\Events\StoreUninstalled;

class RevokeSessionsOnUninstall
{
    public function __construct(private EmbeddedSessionService $sessions) {}

    public function handle(StoreUninstalled $event): void
    {
        $this->sessions->revokeForMerchant($event->merchantId);
    }
}
