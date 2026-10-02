<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Enums\ActivityLevel;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\AppEvent;
use App\Models\AppFeedback;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\MerchantToken;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ActivityLogsTable
{
    private const PERIODS = [
        '15m' => 'Last 15 minutes',
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        'all' => 'Any time (everything retained)',
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
            ->paginationMode(PaginationMode::Simple)
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->searchPlaceholder('Search message, action, URL, IP, correlation ID, actor, merchant…')
            ->description(function (): string {
                $days = (int) config('activity-log.retention_days');

                return $days > 0
                    ? "Entries are kept for {$days} days, then deleted automatically. Times are shown in ".config('app.timezone').'.'
                    : 'Entries are kept indefinitely. Times are shown in '.config('app.timezone').'.';
            })
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No log entries match')
            ->emptyStateDescription('Try a longer period, another tab, or clear the filters.');
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function defaultColumns(): array
    {
        return [
            TextColumn::make('created_at')
                ->label('When')
                ->dateTime('Y-m-d H:i:s')
                ->description(fn (ActivityLog $record): string => $record->created_at->diffForHumans())
                ->sortable(),
            TextColumn::make('level')->badge(),
            TextColumn::make('channel')
                ->badge()
                ->sortable()
                ->formatStateUsing(fn (string $state): string => ActivityLogResource::CHANNELS[$state]['label'] ?? $state)
                ->color(fn (string $state): string => ActivityLogResource::CHANNELS[$state]['color'] ?? 'gray')
                ->icon(fn (string $state) => ActivityLogResource::CHANNELS[$state]['icon'] ?? null),
            TextColumn::make('action')
                ->searchable()
                ->sortable()
                ->fontFamily(FontFamily::Mono)
                ->copyable(),
            TextColumn::make('message')
                ->searchable()
                ->limit(90)
                ->tooltip(fn (ActivityLog $record): ?string => strlen((string) $record->message) > 90 ? $record->message : null)
                ->wrap()
                ->placeholder('—'),
            TextColumn::make('status_code')
                ->label('Status')
                ->badge()
                ->sortable()
                ->placeholder('—')
                ->color(fn (?int $state): string => match (true) {
                    $state === null => 'gray',
                    $state >= 500 => 'danger',
                    $state >= 400 => 'warning',
                    $state >= 300 => 'info',
                    default => 'success',
                }),
            TextColumn::make('duration_ms')
                ->label('Duration')
                ->suffix(' ms')
                ->numeric(0)
                ->sortable()
                ->placeholder('—'),
            TextColumn::make('actor_type')
                ->label('Actor')
                ->formatStateUsing(fn ($state, ActivityLog $record): string => $record->actorLabel())
                ->searchable(['actor_type', 'actor_id']),
            TextColumn::make('merchant.name')
                ->label('Merchant')
                ->placeholder('—')
                ->description(fn (ActivityLog $record): ?string => $record->merchant_id ? 'ID '.$record->merchant_id : null)
                ->searchable()
                ->sortable(),
        ];
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function hiddenColumns(): array
    {
        $columns = [
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('http_method')->label('Method')->badge()->sortable()->placeholder('—'),
            TextColumn::make('url')
                ->searchable()
                ->limit(60)
                ->tooltip(fn (ActivityLog $record): ?string => $record->url)
                ->placeholder('—'),
            TextColumn::make('ip_address')->label('IP')->searchable()->sortable()->copyable()->placeholder('—'),
            TextColumn::make('user_agent')
                ->searchable()
                ->limit(50)
                ->tooltip(fn (ActivityLog $record): ?string => $record->user_agent)
                ->placeholder('—'),
            TextColumn::make('correlation_id')
                ->label('Correlation ID')
                ->searchable()
                ->copyable()
                ->fontFamily(FontFamily::Mono)
                ->limit(13)
                ->tooltip(fn (ActivityLog $record): ?string => $record->correlation_id)
                ->placeholder('—'),
            TextColumn::make('subject_type')
                ->label('Subject')
                ->formatStateUsing(fn ($state, ActivityLog $record): ?string => $record->subjectLabel())
                ->url(fn (ActivityLog $record): ?string => $record->subjectUrl())
                ->searchable(['subject_type', 'subject_id'])
                ->placeholder('—'),
            TextColumn::make('merchant_id')->label('Merchant ID')->searchable()->sortable()->placeholder('—'),
            TextColumn::make('context')
                ->label('Context (search only)')
                ->searchable()
                ->formatStateUsing(fn ($state): ?string => null),
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
            self::periodFilter(),
            self::createdRangeFilter(),
            SelectFilter::make('level')->options(ActivityLevel::class)->multiple(),
            self::textFilter('action', 'Action contains', fn (Builder $query, string $value): Builder => $query
                ->whereRaw("action like ? escape '!'", ['%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']), 'e.g. salla. or http.request'),
            SelectFilter::make('http_method')
                ->label('HTTP method')
                ->options(array_combine(
                    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                ))
                ->multiple(),
            self::statusClassFilter(),
            self::slowerThanFilter(),
            self::actorKindFilter(),
            self::textFilter('actor_id', 'Actor ID', fn (Builder $query, string $value): Builder => $query->where('actor_id', $value)),
            SelectFilter::make('merchant')
                ->relationship('merchant', 'name')
                ->searchable(['name', 'email', 'domain', 'merchant_id'])
                ->getOptionLabelFromRecordUsing(fn (Merchant $record): string => ($record->name ?? 'Unnamed store').' ('.$record->merchant_id.')')
                ->multiple(),
            self::textFilter('merchant_id', 'Merchant ID', fn (Builder $query, string $value): Builder => $query->where('merchant_id', (int) $value)),
            SelectFilter::make('subject_type')
                ->label('Subject type')
                ->multiple()
                ->options([
                    AppEvent::class => 'Webhook event',
                    Merchant::class => 'Merchant',
                    MerchantToken::class => 'Merchant token',
                    MerchantSetting::class => 'Merchant setting',
                    AppFeedback::class => 'Feedback',
                    Subscription::class => 'Subscription',
                    SubscriptionPeriod::class => 'Subscription period',
                    SubscriptionFeature::class => 'Subscription feature',
                    Plan::class => 'Plan',
                    PlanPrice::class => 'Plan price',
                    PlanFeature::class => 'Plan feature',
                    User::class => 'User',
                ]),
            self::textFilter('subject_id', 'Subject ID', fn (Builder $query, string $value): Builder => $query->where('subject_id', $value)),
            TernaryFilter::make('has_exception')
                ->label('Has exception')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('context->exception'),
                    false: fn (Builder $query): Builder => $query->whereNull('context->exception'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            self::textFilter('correlation', 'Correlation ID', fn (Builder $query, string $value): Builder => $query->where('correlation_id', $value)),
            self::textFilter('ip_address', 'IP address', fn (Builder $query, string $value): Builder => $query->where('ip_address', $value)),
        ];
    }

    private static function periodFilter(): Filter
    {
        return Filter::make('period')
            ->label('Period')
            ->schema([
                Select::make('value')
                    ->label('Period')
                    ->options(self::PERIODS)
                    ->native(false)
                    ->default('24h')
                    ->selectablePlaceholder(false),
            ])
            ->default(['value' => '24h'])
            ->query(function (Builder $query, array $data): Builder {
                $since = match ($data['value'] ?? 'all') {
                    '15m' => now()->subMinutes(15),
                    '1h' => now()->subHour(),
                    '24h' => now()->subHours(24),
                    '7d' => now()->subDays(7),
                    default => null,
                };

                return $since ? $query->where('created_at', '>=', $since) : $query;
            })
            ->indicateUsing(fn (array $data): ?string => ($data['value'] ?? 'all') === 'all'
                ? null
                : 'Period: '.self::PERIODS[$data['value']]);
    }

    private static function createdRangeFilter(): Filter
    {
        return Filter::make('created_range')
            ->label('Between')
            ->schema([
                DateTimePicker::make('from')->seconds(false),
                DateTimePicker::make('until')->seconds(false),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', Carbon::parse($from)->startOfMinute()))
                ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('created_at', '<', Carbon::parse($until)->startOfMinute()->addMinute())))
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = 'Since '.Carbon::parse($data['from'])->format('Y-m-d H:i');
                }

                if ($data['until'] ?? null) {
                    $indicators[] = 'Until '.Carbon::parse($data['until'])->format('Y-m-d H:i');
                }

                return $indicators;
            });
    }

    /**
     * A single text input filter that applies $apply when filled.
     *
     * @param  callable(Builder<ActivityLog>, string): Builder<ActivityLog>  $apply
     */
    private static function textFilter(string $name, string $label, callable $apply, ?string $placeholder = null): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                TextInput::make('value')->label($label)->placeholder($placeholder),
            ])
            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                ? $apply($query, trim((string) $data['value']))
                : $query)
            ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                ? $label.': '.trim((string) $data['value'])
                : null);
    }

    private static function statusClassFilter(): SelectFilter
    {
        return SelectFilter::make('status_class')
            ->label('HTTP status')
            ->multiple()
            ->options([
                '2xx' => '2xx Success',
                '3xx' => '3xx Redirect',
                '4xx' => '4xx Client error',
                '5xx' => '5xx Server error',
                'none' => 'No status (not an HTTP entry)',
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                filled($data['values'] ?? null),
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($data): void {
                    foreach ($data['values'] as $class) {
                        match ($class) {
                            '2xx' => $query->orWhereBetween('status_code', [200, 299]),
                            '3xx' => $query->orWhereBetween('status_code', [300, 399]),
                            '4xx' => $query->orWhereBetween('status_code', [400, 499]),
                            '5xx' => $query->orWhereBetween('status_code', [500, 599]),
                            'none' => $query->orWhereNull('status_code'),
                            default => null,
                        };
                    }
                }),
            ));
    }

    private static function slowerThanFilter(): Filter
    {
        return Filter::make('slower_than')
            ->label('Slower than')
            ->schema([
                TextInput::make('value')->label('Slower than (ms)')->numeric()->minValue(0),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                filled($data['value'] ?? null),
                fn (Builder $query): Builder => $query->where('duration_ms', '>=', (int) $data['value']),
            ))
            ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                ? 'Slower than '.(int) $data['value'].' ms'
                : null);
    }

    private static function actorKindFilter(): SelectFilter
    {
        return SelectFilter::make('actor_kind')
            ->label('Actor')
            ->multiple()
            ->options([
                'user' => 'Signed-in user',
                'salla_merchant' => 'Salla merchant',
                'system' => 'System',
                'guest' => 'Guest / none',
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                filled($data['values'] ?? null),
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($data): void {
                    foreach ($data['values'] as $kind) {
                        match ($kind) {
                            'user' => $query->orWhere('actor_type', User::class),
                            'salla_merchant' => $query->orWhere('actor_type', 'salla_merchant'),
                            'system' => $query->orWhere('actor_type', 'system'),
                            'guest' => $query->orWhereNull('actor_type'),
                            default => null,
                        };
                    }
                }),
            ));
    }
}
