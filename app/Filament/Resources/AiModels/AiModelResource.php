<?php

namespace App\Filament\Resources\AiModels;

use App\Filament\Resources\AiModels\Pages\ManageAiModels;
use App\Models\AiModel;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AiModelResource extends Resource
{
    protected static ?string $model = AiModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Model')->columns(2)->components([
                Select::make('provider')->options(['bedrock' => 'AWS Bedrock', 'fal' => 'fal.ai'])->required(),
                TextInput::make('provider_model_id')->label('Provider model id')->required()->maxLength(255),
                TextInput::make('name_ar')->label('Name (Arabic)')->required()->maxLength(255),
                TextInput::make('name_en')->label('Name (English)')->required()->maxLength(255),
                CheckboxList::make('features')->options(['product_content' => 'Product content', 'field_regeneration' => 'Field regeneration', 'image_edit' => 'Image edit', 'image_analysis' => 'Image analysis', 'text_moderation' => 'Text moderation', 'image_moderation' => 'Image moderation', 'chat' => 'Chat'])->columns(2)->columnSpanFull(),
                Toggle::make('capabilities.vision')->label('Can read images'),
                Toggle::make('capabilities.tool_use')->label('Supports tool use (structured output)'),
                CheckboxList::make('default_for')->label('Default for')->options(['product_content' => 'Product content', 'field_regeneration' => 'Field regeneration', 'image_edit' => 'Image edit', 'image_analysis' => 'Image analysis', 'text_moderation' => 'Text moderation', 'image_moderation' => 'Image moderation', 'chat' => 'Chat'])->columns(2),
                Toggle::make('active')->default(true),
                TextInput::make('sort')->numeric()->default(0),
            ]),
            Section::make('Prices and costs')->columns(2)->components([
                KeyValue::make('prices')->label('Credit price per action')->keyLabel('Action')->valueLabel('Credits')->helperText('Overrides the default price for this model.'),
                KeyValue::make('cost_rates')->label('Provider cost rates (USD)')->keyLabel('Rate')->valueLabel('USD'),
                Textarea::make('param_schema')->label('Parameter schema (JSON)')->rows(8)->columnSpanFull()
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $state)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode((string) $state, true) : null)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail): void {
                        if (filled($value) && ! is_array(json_decode((string) $value, true))) {
                            $fail('The parameter schema must be valid JSON.');
                        }
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('provider')->badge()->sortable(),
                TextColumn::make('provider_model_id')->label('Model id')->searchable()->copyable(),
                TextColumn::make('features')->badge()->separator(',')->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)->wrap(),
                TextColumn::make('default_for')->label('Default for')->badge()->wrap(),
                IconColumn::make('active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')->options(['bedrock' => 'AWS Bedrock', 'fal' => 'fal.ai']),
                TernaryFilter::make('active'),
            ])
            ->recordActions([EditAction::make()])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiModels::route('/')];
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
