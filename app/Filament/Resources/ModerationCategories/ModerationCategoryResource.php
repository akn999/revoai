<?php

namespace App\Filament\Resources\ModerationCategories;

use App\Filament\Resources\ModerationCategories\Pages\ManageModerationCategories;
use App\Models\ModerationCategory;
use BackedEnum;
use Filament\Actions\EditAction;
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
use Illuminate\Database\Eloquent\Model;

class ModerationCategoryResource extends Resource
{
    protected static ?string $model = ModerationCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('Name (Arabic)')->required()->maxLength(255),
            TextInput::make('name_en')->label('Name (English)')->required()->maxLength(255),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Textarea::make('instruction')->label('Instruction to the model')->required()->rows(4)->columnSpanFull(),
            Toggle::make('active'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('key')->badge(),
                TextColumn::make('instruction')->limit(70)->wrap(),
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
        return ['index' => ManageModerationCategories::route('/')];
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
