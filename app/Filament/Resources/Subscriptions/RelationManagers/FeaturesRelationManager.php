<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'features';

    protected static ?string $title = 'Features';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('feature_key')
            ->columns([
                TextColumn::make('feature_key')->searchable()->sortable(),
                TextColumn::make('quantity')->numeric(0)->sortable(),
            ])
            ->defaultSort('feature_key')
            ->paginated([10, 25, 50]);
    }
}
