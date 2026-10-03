<?php

namespace App\Filament\Resources\PurchaseIntents;

use App\Billing\PurchaseService;
use App\Filament\Resources\PurchaseIntents\Pages\ManagePurchaseIntents;
use App\Models\PurchaseIntent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseIntentResource extends Resource
{
    protected static ?string $model = PurchaseIntent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'purchase';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('pack');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('merchant_id')->label('Merchant')->searchable()->sortable(),
                TextColumn::make('pack.name_en')->label('Pack')->placeholder('—'),
                TextColumn::make('credits')->numeric()->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    PurchaseIntent::CONFIRMED => 'success', PurchaseIntent::PENDING => 'warning', PurchaseIntent::FAILED, PurchaseIntent::REVERSED => 'danger', default => 'gray',
                })->sortable(),
                TextColumn::make('salla_order_id')->label('Salla order')->searchable()->copyable()->placeholder('—'),
                TextColumn::make('verifier_strategy')->label('Verified by')->badge()->placeholder('—'),
                IconColumn::make('reconciled_at')->label('Reconciled')->boolean()->getStateUsing(fn (PurchaseIntent $record) => $record->reconciled_at !== null),
                TextColumn::make('reversal_shortfall')->label('Shortfall')->numeric()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    PurchaseIntent::CREATED => 'Created', PurchaseIntent::PENDING => 'Pending', PurchaseIntent::CONFIRMED => 'Confirmed',
                    PurchaseIntent::FAILED => 'Failed', PurchaseIntent::EXPIRED => 'Expired', PurchaseIntent::REVERSED => 'Reversed',
                ]),
                TernaryFilter::make('unreconciled')->label('Unreconciled confirmed')->queries(
                    true: fn (Builder $query) => $query->where('status', PurchaseIntent::CONFIRMED)->whereNull('reconciled_at'),
                    false: fn (Builder $query) => $query->whereNotNull('reconciled_at'),
                    blank: fn (Builder $query) => $query,
                ),
            ])
            ->recordActions([
                Action::make('reconcile')->label('Mark reconciled')->icon(Heroicon::OutlinedCheckBadge)->requiresConfirmation()
                    ->visible(fn (PurchaseIntent $record) => $record->status === PurchaseIntent::CONFIRMED && $record->reconciled_at === null)
                    ->action(function (PurchaseIntent $record): void {
                        app(PurchaseService::class)->markReconciled($record, 'admin:'.auth('admin')->id());
                        Notification::make()->title('Marked as reconciled')->success()->send();
                    }),
                Action::make('reverse')->label('Reverse')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')->requiresConfirmation()
                    ->modalDescription('Deducts the purchased credits, up to the available balance. Any shortfall is recorded.')
                    ->visible(fn (PurchaseIntent $record) => $record->status === PurchaseIntent::CONFIRMED)
                    ->action(function (PurchaseIntent $record): void {
                        $result = app(PurchaseService::class)->reverse($record, 'admin:'.auth('admin')->id());
                        Notification::make()->title($result->reversal_shortfall > 0 ? "Reversed with a shortfall of {$result->reversal_shortfall} credits" : 'Purchase reversed')->warning()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePurchaseIntents::route('/')];
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
