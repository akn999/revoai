<?php

namespace App\Platform\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The app was uninstalled from a store. Modules react: revoke sessions, release credit
 * reservations, cancel queued AI work (FR-INS-004).
 */
class StoreUninstalled
{
    use Dispatchable;

    public function __construct(public int $merchantId) {}
}
