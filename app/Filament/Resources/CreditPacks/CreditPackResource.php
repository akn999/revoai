<?php

namespace App\Filament\Resources\CreditPacks;

use App\Filament\Resources\CreditPacks\Pages\ManageCreditPacks;
use App\Models\CreditPack;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CreditPackResource extends Resource
{
    protected static ?string $model = CreditPack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('salla_addon_slug')->label('Salla add-on slug')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('credits')->numeric()->required()->minValue(1),
            TextInput::make('name_ar')->label('Name (Arabic)')->required()->maxLength(255),
            TextInput::make('name_en')->label('Name (English)')->required()->maxLength(255),
            TextInput::make('sort')->numeric()->default(0),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('salla_addon_slug')->label('Add-on')->searchable()->copyable(),
                TextColumn::make('credits')->numeric()->sortable(),
                ToggleColumn::make('active'),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->recordActions([EditAction::make()])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCreditPacks::route('/')];
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
