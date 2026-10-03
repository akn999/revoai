<?php

namespace App\Filament\Resources\ContextOptions;

use App\Filament\Resources\ContextOptions\Pages\ManageContextOptions;
use App\Models\ContextOption;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContextOptionResource extends Resource
{
    protected static ?string $model = ContextOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|\UnitEnum|null $navigationGroup = 'Content defaults';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label_en';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('field')->options(['industry' => 'Industry', 'audience' => 'Target audience', 'brand_tone' => 'Brand tone', 'photography_style' => 'Photography style'])->required(),
            TextInput::make('key')->required()->maxLength(100)->alphaDash(),
            TextInput::make('label_ar')->label('Label (Arabic)')->required()->maxLength(255),
            TextInput::make('label_en')->label('Label (English)')->required()->maxLength(255),
            TextInput::make('sort')->numeric()->default(0),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('field')->badge()->sortable(),
                TextColumn::make('key')->searchable(),
                TextColumn::make('label_en')->label('English')->searchable(),
                TextColumn::make('label_ar')->label('Arabic')->searchable(),
                TextColumn::make('sort')->sortable(),
                ToggleColumn::make('active'),
            ])
            ->filters([
                SelectFilter::make('field')->options(['industry' => 'Industry', 'audience' => 'Target audience', 'brand_tone' => 'Brand tone', 'photography_style' => 'Photography style']),
                TernaryFilter::make('active'),
            ])
            ->recordActions([EditAction::make()])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageContextOptions::route('/')];
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
