<?php

namespace App\Filament\Widgets;

use App\Enums\MerchantStatus;
use App\Models\FailedJob;
use App\Models\Merchant;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The first things an operator checks: stores in trouble, plans nobody mapped, jobs that failed.
 */
class PlatformHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Platform health';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $unmapped = Subscription::query()->withoutGlobalScopes()->whereNull('plan_id')->count();

        return [
            Stat::make('Active stores', Merchant::query()->where('status', MerchantStatus::Active)->count()),
            Stat::make('Need re-authorization', Merchant::query()->where('reauth_required', true)->count())->color('warning'),
            Stat::make('Unmapped Salla plans', $unmapped)->description($unmapped > 0 ? 'Map them under Billing → Plans' : 'All plans mapped')->color($unmapped > 0 ? 'danger' : 'success'),
            Stat::make('Failed jobs', FailedJob::query()->count())->color(FailedJob::query()->exists() ? 'danger' : 'success'),
        ];
    }
}
