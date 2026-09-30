<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'periods';

    protected static ?string $title = 'Billing periods';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('kind')
            ->columns([
                TextColumn::make('kind')->badge()->sortable()->searchable(),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('renew_date')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('price')->money('SAR')->sortable()->placeholder('—'),
                TextColumn::make('tax_value')->money('SAR')->sortable()->placeholder('—'),
                TextColumn::make('total')->money('SAR')->sortable()->placeholder('—'),
                TextColumn::make('coupon_code')->searchable()->placeholder('—'),
                TextColumn::make('app_event_id')->label('Webhook event ID')->sortable()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->options(['trial' => 'Trial', 'start' => 'Start', 'renewal' => 'Renewal']),
            ])
            ->defaultSort('starts_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
