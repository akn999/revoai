<?php

namespace App\Filament\Resources\Presets;

use App\Filament\Resources\Presets\Pages\ManagePresets;
use App\Models\Preset;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PresetResource extends Resource
{
    protected static ?string $model = Preset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Content defaults';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('merchant_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('Name (Arabic)')->required()->maxLength(255),
            TextInput::make('name_en')->label('Name (English)')->required()->maxLength(255),
            Textarea::make('prompt')->required()->rows(5)->maxLength(5000)->columnSpanFull(),
            Select::make('ai_model_id')->label('Image model')->relationship('model', 'name_en', fn ($query) => $query->where('provider', 'fal'))->searchable()->preload(),
            TextInput::make('price_override')->label('Price override (credits)')->numeric()->minValue(0),
            KeyValue::make('params')->label('Parameters')->columnSpanFull(),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('Arabic name')->searchable(),
                TextColumn::make('model.name_en')->label('Model')->placeholder('Store default'),
                TextColumn::make('price_override')->label('Price')->placeholder('Model/default')->numeric(),
                ToggleColumn::make('active'),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->reorderable('sort')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePresets::route('/')];
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
        return true;
    }
}
