<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Resources\ActivityLogs\Schemas\ActivityLogInfolist;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActivityLogResource extends Resource
{
    /**
     * The known channels. Unknown (future) channels render as a gray badge and
     * are reachable through the "All" tab.
     *
     * @var array<string, array{label: string, color: string, icon: Heroicon}>
     */
    public const CHANNELS = [
        'web' => ['label' => 'Web', 'color' => 'primary', 'icon' => Heroicon::OutlinedGlobeAlt],
        'api' => ['label' => 'API', 'color' => 'info', 'icon' => Heroicon::OutlinedCodeBracket],
        'webhook' => ['label' => 'Webhooks', 'color' => 'warning', 'icon' => Heroicon::OutlinedArrowsRightLeft],
        'user' => ['label' => 'Users', 'color' => 'success', 'icon' => Heroicon::OutlinedUserCircle],
        'model' => ['label' => 'Models', 'color' => 'gray', 'icon' => Heroicon::OutlinedCircleStack],
        'system' => ['label' => 'System', 'color' => 'danger', 'icon' => Heroicon::OutlinedCog6Tooth],
        'outbound' => ['label' => 'Outbound', 'color' => 'info', 'icon' => Heroicon::OutlinedArrowUpOnSquare],
        'log' => ['label' => 'App log', 'color' => 'gray', 'icon' => Heroicon::OutlinedDocumentText],
    ];

    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $pluralModelLabel = 'Activity log';

    protected static ?string $modelLabel = 'Log entry';

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('merchant');
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record ? "Log entry #{$record->getKey()}" : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ActivityLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
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

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
