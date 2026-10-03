<?php

namespace App\Filament\Resources\WebhookEvents;

use App\Enums\AppEventStatus;
use App\Filament\Resources\WebhookEvents\Pages\ManageWebhookEvents;
use App\Models\AppEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WebhookEventResource extends Resource
{
    protected static ?string $model = AppEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'webhook event';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('merchant');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('event')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('merchant_id'),
            TextEntry::make('attempts'),
            TextEntry::make('event_created_at')->dateTime(),
            TextEntry::make('processed_at')->dateTime()->placeholder('—'),
            TextEntry::make('error')->columnSpanFull()->placeholder('—'),
            TextEntry::make('payload_json')->label('Payload')->columnSpanFull()->fontFamily(FontFamily::Mono)->copyable()
                ->state(fn (AppEvent $record): string => json_encode($record->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('event')->badge()->searchable()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state instanceof AppEventStatus ? $state : AppEventStatus::tryFrom((string) $state)) {
                    AppEventStatus::Processed => 'success', AppEventStatus::Failed => 'danger', AppEventStatus::Ignored => 'gray', default => 'warning',
                })->sortable(),
                TextColumn::make('merchant_id')->label('Merchant')->searchable()->sortable(),
                TextColumn::make('attempts')->numeric()->sortable(),
                TextColumn::make('error')->limit(60)->placeholder('—')->toggleable(),
                TextColumn::make('event_created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(AppEventStatus::cases())->mapWithKeys(fn (AppEventStatus $s) => [$s->value => ucfirst($s->value)])->all()),
                SelectFilter::make('event')->options(fn () => AppEvent::query()->withoutGlobalScope('merchant')->distinct()->orderBy('event')->pluck('event', 'event')->all())->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('replay')->label('Replay')->icon(Heroicon::OutlinedArrowPath)->requiresConfirmation()
                    ->action(function (AppEvent $record): void {
                        $record->replay();
                        Notification::make()->title('Event queued for replay')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWebhookEvents::route('/')];
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
