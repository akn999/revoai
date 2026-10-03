<?php

namespace App\Filament\Resources\Merchants;

use App\Billing\WalletService;
use App\Enums\MerchantStatus;
use App\Filament\Resources\Merchants\Pages\ManageMerchants;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageLedger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MerchantResource extends Resource
{
    protected static ?string $model = Merchant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Merchants';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('wallet')->addSelect([
            'usage_this_month' => UsageLedger::query()->selectRaw('coalesce(sum(credits_charged), 0)')
                ->whereColumn('usage_ledger.merchant_id', 'merchants.merchant_id')->where('internal', false)->where('created_at', '>=', now()->startOfMonth()),
        ])->addSelect('merchants.*');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Store')->columns(3)->components([
                TextEntry::make('name')->placeholder('Unnamed'),
                TextEntry::make('merchant_id')->copyable(),
                TextEntry::make('status')->badge(),
                TextEntry::make('domain')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('default_language')->placeholder('—'),
                TextEntry::make('plan_code')->badge()->placeholder('No plan'),
                TextEntry::make('plan_status')->badge()->placeholder('—'),
                TextEntry::make('reauth_required')->label('Re-authorization needed')->badge()->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ]),
            Section::make('Credits')->columns(3)->components([
                TextEntry::make('wallet.balance')->label('Balance')->numeric()->placeholder('0'),
                TextEntry::make('wallet.reserved')->label('Reserved')->numeric()->placeholder('0'),
                TextEntry::make('usage_this_month')->label('Credits used this month')->numeric(),
            ]),
            Section::make('Dates')->columns(3)->components([
                TextEntry::make('installed_at')->dateTime()->placeholder('—'),
                TextEntry::make('sync_finished_at')->label('Catalog synced')->dateTime()->placeholder('—'),
                TextEntry::make('purge_at')->label('Purge scheduled')->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->placeholder('Unnamed')->sortable(),
                TextColumn::make('merchant_id')->label('Merchant ID')->searchable()->copyable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('plan_code')->label('Plan')->badge()->placeholder('—')->sortable(),
                TextColumn::make('wallet.balance')->label('Balance')->numeric()->placeholder('0'),
                TextColumn::make('usage_this_month')->label('Used this month')->numeric(),
                TextColumn::make('installed_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('domain')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(MerchantStatus::cases())->mapWithKeys(fn (MerchantStatus $s) => [$s->value => ucfirst($s->value)])->all()),
                SelectFilter::make('plan_code')->label('Plan')->options(fn () => Plan::query()->pluck('name', 'slug')->all()),
                TernaryFilter::make('reauth_required')->label('Needs re-authorization'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::creditAction('grant'),
                self::creditAction('deduct'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    private static function creditAction(string $kind): Action
    {
        $grant = $kind === 'grant';

        return Action::make($kind)
            ->label($grant ? 'Grant credits' : 'Deduct credits')
            ->icon($grant ? Heroicon::OutlinedPlusCircle : Heroicon::OutlinedMinusCircle)
            ->color($grant ? 'success' : 'danger')
            ->modalDescription($grant ? null : 'A deduction never takes the balance below zero and never touches reserved credits.')
            ->schema([
                TextInput::make('amount')->numeric()->required()->integer()->minValue(1)->maxValue(1_000_000),
                Textarea::make('reason')->required()->minLength(3)->maxLength(500)->rows(3),
            ])
            ->action(function (Merchant $record, array $data) use ($grant): void {
                $wallets = app(WalletService::class);
                $actor = 'admin:'.auth('admin')->id();

                if ($grant) {
                    $wallets->credit($record->merchant_id, (int) $data['amount'], 'manual_grant', $data['reason'], $actor);
                    Notification::make()->title("Granted {$data['amount']} credits")->success()->send();

                    return;
                }

                $result = $wallets->deduct($record->merchant_id, (int) $data['amount'], $data['reason'], $actor);
                $short = $result['shortfall'] > 0 ? " ({$result['shortfall']} not deducted: balance too low)" : '';
                Notification::make()->title("Deducted {$result['deducted']} credits{$short}")->warning()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageMerchants::route('/')];
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
