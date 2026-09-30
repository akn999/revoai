<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\BillingCycle;
use App\Enums\MerchantStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Merchant;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SubscriptionsTable
{
    private const ITEM_TYPES = ['plan' => 'Plan', 'addon' => 'Add-on'];

    private const PLAN_TYPES = ['recurring' => 'Recurring', 'one_time' => 'One time'];

    private const STORE_TYPES = ['development' => 'Development', 'demo' => 'Demo', 'live' => 'Live'];

    private const PERIOD_KINDS = ['trial' => 'Trial', 'start' => 'Start', 'renewal' => 'Renewal'];

    private const CHANGE_TYPES = [
        'started' => 'Started',
        'renewed' => 'Renewed',
        'canceled' => 'Canceled',
        'expired' => 'Expired',
        'superseded' => 'Superseded',
        'plan_changed' => 'Plan changed',
        'cycle_changed' => 'Cycle changed',
        'quantity_changed' => 'Quantity changed',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ...self::defaultColumns(),
                ...self::hiddenColumns(),
            ])
            ->filters(self::filters())
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Search subscription ID, store, email, domain, plan, coupon…')
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No subscriptions yet')
            ->emptyStateDescription('Subscriptions appear here when Salla sends subscription events.');
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function defaultColumns(): array
    {
        return [
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('merchant.name')
                ->label('Store')
                ->searchable()
                ->sortable()
                ->placeholder('Unnamed store')
                ->description(fn (Subscription $record): string => 'Merchant ID '.$record->merchant_id),
            TextColumn::make('salla_subscription_id')
                ->label('Salla subscription ID')
                ->searchable()
                ->sortable()
                ->copyable()
                ->placeholder('— (trial)'),
            TextColumn::make('item_type')
                ->label('Type')
                ->badge()
                ->sortable()
                ->formatStateUsing(fn (string $state): string => $state === 'addon' ? 'Add-on' : 'Plan')
                ->color(fn (string $state): string => $state === 'addon' ? 'info' : 'primary'),
            TextColumn::make('plan_name')->label('Plan / add-on name')->searchable()->sortable()->placeholder('—'),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('billing_cycle')->label('Cycle')->badge()->sortable(),
            TextColumn::make('quantity')->numeric(0)->sortable(),
            TextColumn::make('starts_at')->dateTime()->sortable()->placeholder('—'),
            TextColumn::make('ends_at')->dateTime()->sortable()->placeholder('No end date'),
            TextColumn::make('total')->money(self::currency())->sortable()->placeholder('—'),
        ];
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function hiddenColumns(): array
    {
        $columns = [
            TextColumn::make('merchant_id')->label('Salla merchant ID')->searchable()->sortable()->copyable(),
            TextColumn::make('merchant.email')->label('Merchant email')->searchable()->sortable(),
            TextColumn::make('merchant.mobile')->label('Merchant mobile')->searchable(),
            TextColumn::make('merchant.domain')->label('Merchant domain')->searchable()->sortable(),
            TextColumn::make('merchant.owner_name')->label('Owner name')->searchable(),
            TextColumn::make('merchant.owner_email')->label('Owner email')->searchable(),
            TextColumn::make('merchant.status')->label('Merchant status')->badge()->sortable(),
            TextColumn::make('merchant.store_type')->label('Merchant store type')->badge()->sortable(),
            TextColumn::make('plan.name')->label('Local plan')->searchable()->sortable()->placeholder('Not mapped'),
            TextColumn::make('item_key')->label('Item key')->searchable()->sortable(),
            TextColumn::make('plan_type')
                ->badge()
                ->sortable()
                ->formatStateUsing(fn (?string $state): string => $state === 'one_time' ? 'One time' : ucfirst((string) $state))
                ->placeholder('—'),
            TextColumn::make('period_months')->label('Period (months)')->numeric(0)->sortable()->placeholder('—'),
            TextColumn::make('plan_period')->label('Raw plan_period')->searchable()->placeholder('—'),
            TextColumn::make('store_type')->badge()->sortable()->placeholder('—'),
            TextColumn::make('price')->money(self::currency())->sortable(),
            TextColumn::make('price_before_discount')->money(self::currency())->sortable(),
            TextColumn::make('initialization_cost')->money(self::currency())->sortable(),
            TextColumn::make('tax_rate')->label('Tax rate (fraction)')->numeric(4)->sortable(),
            TextColumn::make('tax_value')->money(self::currency())->sortable(),
            TextColumn::make('currency')->searchable()->sortable(),
            TextColumn::make('coupon_code')->searchable()->sortable()->placeholder('—'),
            TextColumn::make('coupon_amount')->numeric(4)->sortable()->placeholder('—'),
            TextColumn::make('renewed_at')->dateTime()->sortable()->placeholder('—'),
            TextColumn::make('canceled_at')->dateTime()->sortable()->placeholder('—'),
            TextColumn::make('expired_at')->dateTime()->sortable()->placeholder('—'),
            TextColumn::make('superseded_at')->dateTime()->sortable()->placeholder('—'),
            TextColumn::make('last_event_at')->label('Last Salla event')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ];

        return array_map(
            fn (TextColumn $column): TextColumn => $column->toggleable(isToggledHiddenByDefault: true),
            $columns,
        );
    }

    /**
     * @return array<int, mixed>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('status')->options(SubscriptionStatus::class)->multiple(),
            SelectFilter::make('item_type')->label('Type')->options(self::ITEM_TYPES),
            SelectFilter::make('billing_cycle')->options(BillingCycle::class)->multiple(),
            SelectFilter::make('plan_type')->options(self::PLAN_TYPES),
            SelectFilter::make('store_type')->label('Subscription store type')->options(self::STORE_TYPES)->multiple(),
            SelectFilter::make('merchant')
                ->relationship('merchant', 'name')
                ->searchable(['name', 'email', 'domain', 'merchant_id'])
                ->getOptionLabelFromRecordUsing(fn (Merchant $record): string => ($record->name ?? 'Unnamed store').' ('.$record->merchant_id.')')
                ->multiple(),
            Filter::make('merchant_status')
                ->label('Merchant status')
                ->schema([
                    Select::make('status')->label('Merchant status')->options(MerchantStatus::class),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['status'] ?? null,
                    fn (Builder $query, string $status): Builder => $query->whereHas('merchant', fn (Builder $merchant) => $merchant->where('status', $status)),
                ))
                ->indicateUsing(fn (array $data): ?string => ($data['status'] ?? null)
                    ? 'Merchant: '.MerchantStatus::from($data['status'])->getLabel()
                    : null),
            SelectFilter::make('plan')->label('Local plan')->relationship('plan', 'name')->searchable()->preload()->multiple(),
            TernaryFilter::make('has_local_plan')->label('Mapped to a local plan')->attribute('plan_id')->nullable(),
            TernaryFilter::make('has_coupon')->label('Has coupon')->attribute('coupon_code')->nullable(),
            TernaryFilter::make('has_end_date')->label('Has end date')->attribute('ends_at')->nullable(),
            Filter::make('entitled_now')
                ->label('Currently grants access')
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->scopes(['entitled'])),
            self::dateRangeFilter('starts_at', 'Starts'),
            self::dateRangeFilter('ends_at', 'Ends'),
            self::dateRangeFilter('renewed_at', 'Renewed'),
            self::dateRangeFilter('canceled_at', 'Canceled'),
            self::dateRangeFilter('expired_at', 'Expired'),
            self::dateRangeFilter('superseded_at', 'Superseded'),
            self::dateRangeFilter('last_event_at', 'Last event'),
            self::dateRangeFilter('created_at', 'Created'),
            self::numberRangeFilter('total', 'Total'),
            self::numberRangeFilter('price', 'Price'),
            self::advancedFilter(),
        ];
    }

    private static function dateRangeFilter(string $column, string $label): Filter
    {
        return Filter::make("{$column}_range")
            ->label("{$label} between")
            ->schema([
                DatePicker::make('from')->label("{$label} from"),
                DatePicker::make('until')->label("{$label} until"),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '<=', $date)))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = "{$label} from ".Carbon::parse($data['from'])->toFormattedDateString();
                }

                if ($data['until'] ?? null) {
                    $indicators[] = "{$label} until ".Carbon::parse($data['until'])->toFormattedDateString();
                }

                return $indicators;
            });
    }

    private static function numberRangeFilter(string $column, string $label): Filter
    {
        return Filter::make("{$column}_range")
            ->label("{$label} range")
            ->schema([
                TextInput::make('min')->label("{$label} min")->numeric(),
                TextInput::make('max')->label("{$label} max")->numeric(),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(filled($data['min'] ?? null), fn (Builder $query): Builder => $query->where($column, '>=', $data['min']))
                ->when(filled($data['max'] ?? null), fn (Builder $query): Builder => $query->where($column, '<=', $data['max'])))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if (filled($data['min'] ?? null)) {
                    $indicators[] = "{$label} ≥ {$data['min']}";
                }

                if (filled($data['max'] ?? null)) {
                    $indicators[] = "{$label} ≤ {$data['max']}";
                }

                return $indicators;
            });
    }

    private static function advancedFilter(): QueryBuilder
    {
        $dates = ['starts_at', 'ends_at', 'renewed_at', 'canceled_at', 'expired_at', 'superseded_at', 'last_event_at', 'created_at', 'updated_at'];

        return QueryBuilder::make('advanced')
            ->label('Advanced filter')
            ->constraintPickerColumns(2)
            ->constraints([
                TextConstraint::make('item_key'),
                TextConstraint::make('plan_name'),
                TextConstraint::make('plan_period'),
                TextConstraint::make('currency'),
                TextConstraint::make('coupon_code'),
                NumberConstraint::make('salla_subscription_id')->label('Salla subscription ID'),
                NumberConstraint::make('quantity'),
                NumberConstraint::make('period_months'),
                NumberConstraint::make('price'),
                NumberConstraint::make('price_before_discount'),
                NumberConstraint::make('initialization_cost'),
                NumberConstraint::make('tax_rate'),
                NumberConstraint::make('tax_value'),
                NumberConstraint::make('total'),
                NumberConstraint::make('coupon_amount'),
                ...array_map(fn (string $column): DateConstraint => DateConstraint::make($column), $dates),
                SelectConstraint::make('status')->options(SubscriptionStatus::class)->multiple(),
                SelectConstraint::make('billing_cycle')->options(BillingCycle::class)->multiple(),
                SelectConstraint::make('item_type')->label('Type')->options(self::ITEM_TYPES),
                SelectConstraint::make('plan_type')->options(self::PLAN_TYPES),
                SelectConstraint::make('store_type')->options(self::STORE_TYPES),

                NumberConstraint::make('merchant_salla_id')->label('Merchant: Salla merchant ID')->relationship('merchant', 'merchant_id'),
                TextConstraint::make('merchant_name')->label('Merchant: name')->relationship('merchant', 'name'),
                TextConstraint::make('merchant_email')->label('Merchant: email')->relationship('merchant', 'email'),
                TextConstraint::make('merchant_mobile')->label('Merchant: mobile')->relationship('merchant', 'mobile'),
                TextConstraint::make('merchant_domain')->label('Merchant: domain')->relationship('merchant', 'domain'),
                TextConstraint::make('merchant_owner_name')->label('Merchant: owner name')->relationship('merchant', 'owner_name'),
                TextConstraint::make('merchant_owner_email')->label('Merchant: owner email')->relationship('merchant', 'owner_email'),
                SelectConstraint::make('merchant_store_type')->label('Merchant: store type')->options(self::STORE_TYPES)->relationship('merchant', 'store_type'),
                SelectConstraint::make('merchant_status')->label('Merchant: status')->options(MerchantStatus::class)->relationship('merchant', 'status'),
                DateConstraint::make('merchant_installed_at')->label('Merchant: installed at')->relationship('merchant', 'installed_at'),
                DateConstraint::make('merchant_uninstalled_at')->label('Merchant: uninstalled at')->relationship('merchant', 'uninstalled_at'),
                DateConstraint::make('merchant_profile_synced_at')->label('Merchant: profile synced at')->relationship('merchant', 'profile_synced_at'),

                TextConstraint::make('local_plan_name')->label('Local plan: name')->relationship('plan', 'name'),
                TextConstraint::make('local_plan_slug')->label('Local plan: slug')->relationship('plan', 'slug'),

                SelectConstraint::make('period_kind')->label('Has period: kind')->options(self::PERIOD_KINDS)->relationship('periods', 'kind'),
                NumberConstraint::make('period_total')->label('Has period: total')->relationship('periods', 'total'),
                TextConstraint::make('period_coupon_code')->label('Has period: coupon code')->relationship('periods', 'coupon_code'),
                DateConstraint::make('period_starts_at')->label('Has period: starts at')->relationship('periods', 'starts_at'),
                DateConstraint::make('period_ends_at')->label('Has period: ends at')->relationship('periods', 'ends_at'),
                DateConstraint::make('period_renew_date')->label('Has period: renew date')->relationship('periods', 'renew_date'),

                TextConstraint::make('feature_key')->label('Has feature: key')->relationship('features', 'feature_key'),
                NumberConstraint::make('feature_quantity')->label('Has feature: quantity')->relationship('features', 'quantity'),

                SelectConstraint::make('change_type')->label('Has change: type')->options(self::CHANGE_TYPES)->relationship('changes', 'change_type'),
                TextConstraint::make('change_from_status')->label('Has change: from status')->relationship('changes', 'from_status'),
                TextConstraint::make('change_to_status')->label('Has change: to status')->relationship('changes', 'to_status'),
                DateConstraint::make('change_occurred_at')->label('Has change: occurred at')->relationship('changes', 'occurred_at'),
            ]);
    }

    private static function currency(): \Closure
    {
        return fn (Subscription $record): string => $record->currency ?? 'SAR';
    }
}
