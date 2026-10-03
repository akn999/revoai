<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\ManagePlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->label('Plan code')->required()->maxLength(100)->alphaDash(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('salla_plan_name')->label('Salla plan name')->maxLength(255)->helperText('Subscriptions from Salla are mapped to this plan by name.'),
            Toggle::make('is_active')->label('Active')->default(true),
            KeyValue::make('feature_flags')->label('Features (1 = on, 0 = off)')->keyLabel('Feature')->valueLabel('On')->columnSpanFull()
                ->formatStateUsing(fn ($state) => collect((array) $state)->map(fn ($on) => $on ? '1' : '0')->all())
                ->dehydrateStateUsing(fn ($state) => collect((array) $state)->map(fn ($on) => in_array((string) $on, ['1', 'true', 'on', 'yes'], true))->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')->label('Code')->badge()->searchable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('salla_plan_name')->label('Salla plan')->placeholder('Unmapped'),
                TextColumn::make('feature_flags')->label('Features')->formatStateUsing(fn ($state) => collect((array) $state)->filter()->keys()->implode(', '))->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make()])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlans::route('/')];
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
