<?php

namespace App\Filament\Resources\PromptDefaults;

use App\Filament\Resources\PromptDefaults\Pages\ManagePromptDefaults;
use App\Models\PromptDefault;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PromptDefaultResource extends Resource
{
    protected static ?string $model = PromptDefault::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|\UnitEnum|null $navigationGroup = 'Content defaults';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->disabled()->dehydrated(false),
            TextInput::make('key')->disabled()->dehydrated(false),
            Textarea::make('body')->label('Default prompt')->required()->rows(12)->maxLength(10000)->columnSpanFull()
                ->helperText('Stores that have not saved their own override follow this text immediately.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('key')->badge(),
                TextColumn::make('body')->limit(80)->wrap(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePromptDefaults::route('/')];
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
