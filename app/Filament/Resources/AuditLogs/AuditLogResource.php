<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'admin audit log';

    public static function infolist(Schema $schema): Schema
    {
        $json = fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—';

        return $schema->columns(2)->components([
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('event')->badge(),
            TextEntry::make('auditable_type')->label('Record type'),
            TextEntry::make('auditable_id')->label('Record id'),
            TextEntry::make('old')->label('Before')->formatStateUsing($json)->fontFamily(FontFamily::Mono),
            TextEntry::make('new')->label('After')->formatStateUsing($json)->fontFamily(FontFamily::Mono),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('admin_user_id')->label('Admin')->formatStateUsing(fn ($state) => $state ? (AdminUser::query()->whereKey((int) $state)->first()->name ?? "#{$state}") : 'System'),
                TextColumn::make('event')->badge()->sortable(),
                TextColumn::make('auditable_type')->label('Record')->formatStateUsing(fn (string $state) => class_basename($state))->searchable(),
                TextColumn::make('auditable_id')->label('ID')->searchable(),
            ])
            ->filters([
                SelectFilter::make('admin_user_id')->label('Admin')->options(fn () => AdminUser::query()->pluck('name', 'id')->all()),
                SelectFilter::make('auditable_type')->label('Record type')->options(fn () => AdminAuditLog::query()->distinct()->pluck('auditable_type')->mapWithKeys(fn ($t) => [$t => class_basename($t)])->all()),
                SelectFilter::make('event')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAuditLogs::route('/')];
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
