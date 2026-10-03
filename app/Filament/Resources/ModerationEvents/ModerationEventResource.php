<?php

namespace App\Filament\Resources\ModerationEvents;

use App\Filament\Resources\ModerationEvents\Pages\ManageModerationEvents;
use App\Models\ModerationCategory;
use App\Models\ModerationEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ModerationEventResource extends Resource
{
    protected static ?string $model = ModerationEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'moderation event';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('merchant');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('merchant_id')->label('Merchant')->searchable()->sortable(),
                TextColumn::make('decision')->badge()->color(fn (string $state) => match ($state) {
                    'allowed' => 'success', 'blocked' => 'danger', default => 'warning',
                })->sortable(),
                TextColumn::make('category')->badge()->placeholder('—'),
                TextColumn::make('subject_type')->label('Subject')->searchable(),
                TextColumn::make('subject_id')->label('Subject ID')->toggleable(),
                TextColumn::make('provider')->badge(),
            ])
            ->filters([
                SelectFilter::make('decision')->options(['allowed' => 'Allowed', 'blocked' => 'Blocked', 'error' => 'Error']),
                SelectFilter::make('category')->options(fn () => ModerationCategory::query()->pluck('name_en', 'key')->all()),
                SelectFilter::make('subject_type')->label('Subject type')->options(fn () => ModerationEvent::query()->withoutGlobalScope('merchant')->distinct()->pluck('subject_type', 'subject_type')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageModerationEvents::route('/')];
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
