<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'Change history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('change_type')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope('merchant'))
            ->columns([
                TextColumn::make('change_type')->badge()->searchable()->sortable(),
                TextColumn::make('from_status')->badge()->placeholder('—'),
                TextColumn::make('to_status')->badge()->placeholder('—'),
                TextColumn::make('from_billing_cycle')->placeholder('—'),
                TextColumn::make('to_billing_cycle')->placeholder('—'),
                TextColumn::make('from_plan_id')->placeholder('—'),
                TextColumn::make('to_plan_id')->placeholder('—'),
                TextColumn::make('app_event_id')->label('Webhook event ID')->placeholder('— (sweep)'),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('change_type')->multiple()->options([
                    'started' => 'Started',
                    'renewed' => 'Renewed',
                    'canceled' => 'Canceled',
                    'expired' => 'Expired',
                    'superseded' => 'Superseded',
                    'plan_changed' => 'Plan changed',
                    'cycle_changed' => 'Cycle changed',
                    'quantity_changed' => 'Quantity changed',
                ]),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
