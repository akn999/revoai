<?php

namespace App\Platform;

use App\Enums\MerchantStatus;
use App\Logging\Activity;
use App\Models\EmbeddedSession;
use App\Models\Merchant;
use App\Platform\Events\StorePurging;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Deletes every tenant row of a store after the grace period. The wallet, credit ledger,
 * purchases, audit logs and usage ledger are never touched (FR-INS-006).
 */
class TenantPurger
{
    public function purge(Merchant $merchant): void
    {
        StorePurging::dispatch($merchant->merchant_id);

        DB::transaction(function () use ($merchant): void {
            foreach ((array) config('revo.tenant_models', []) as $class) {
                /** @var class-string<Model> $class */
                $query = $class::query()->withoutGlobalScopes()->where('merchant_id', $merchant->merchant_id);

                in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $query->forceDelete() : $query->delete();
            }

            EmbeddedSession::query()->where('merchant_id', $merchant->merchant_id)->delete();

            $merchant->forceFill([
                'status' => MerchantStatus::Purged,
                'name' => null, 'email' => null, 'mobile' => null, 'domain' => null, 'avatar' => null,
                'owner_name' => null, 'owner_email' => null,
                'purge_at' => null,
            ])->save();
        });

        Activity::channel('system')->bySystem()->forMerchant($merchant->merchant_id)
            ->warning('store.purged', 'Tenant data purged after the uninstall grace period');
    }

    /**
     * @return int Number of stores purged.
     */
    public function purgeDue(): int
    {
        $count = 0;

        Merchant::withoutGlobalScopes()
            ->where('status', MerchantStatus::Uninstalled->value)
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now())
            ->each(function (Merchant $merchant) use (&$count): void {
                $this->purge($merchant);
                $count++;
            });

        return $count;
    }
}
