<?php

namespace App\Console\Commands;

use App\Platform\TenantPurger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('revo:stores:purge')]
#[Description('Purge tenant data of stores whose uninstall grace period has ended')]
class PurgeStores extends Command
{
    public function handle(TenantPurger $purger): int
    {
        $this->info("Purged {$purger->purgeDue()} store(s).");

        return self::SUCCESS;
    }
}
