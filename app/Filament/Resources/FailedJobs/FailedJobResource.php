<?php

namespace App\Filament\Resources\FailedJobs;

use App\Filament\Resources\FailedJobs\Pages\ManageFailedJobs;
use App\Models\FailedJob;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'failed job';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('uuid')->copyable(),
            TextEntry::make('queue')->badge(),
            TextEntry::make('failed_at')->dateTime(),
            TextEntry::make('job')->state(fn (FailedJob $record) => $record->displayName())->columnSpanFull(),
            TextEntry::make('exception')->columnSpanFull()->fontFamily(FontFamily::Mono)->copyable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('failed_at')->dateTime()->sortable(),
                TextColumn::make('job')->state(fn (FailedJob $record) => $record->displayName())->searchable(query: fn ($query, string $search) => $query->where('payload', 'like', '%'.addcslashes($search, '%_\\').'%')),
                TextColumn::make('queue')->badge()->sortable(),
                TextColumn::make('exception')->limit(80)->wrap()->searchable(),
            ])
            ->filters([
                SelectFilter::make('queue')->options(fn () => FailedJob::query()->distinct()->pluck('queue', 'queue')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')->label('Retry')->icon(Heroicon::OutlinedArrowPath)->requiresConfirmation()
                    ->action(function (FailedJob $record): void {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);
                        Notification::make()->title('Job queued again')->success()->send();
                    }),
            ])
            ->defaultSort('failed_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFailedJobs::route('/')];
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
