<?php

namespace App\Filament\Resources\ActivityLogs\Pages;

use App\Enums\ActivityLevel;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('All')->icon(Heroicon::OutlinedSquares2x2),
            'problems' => Tab::make('Problems')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn(
                    'level',
                    array_map(fn (ActivityLevel $level): string => $level->value, ActivityLevel::problems()),
                )),
        ];

        foreach (ActivityLogResource::CHANNELS as $channel => $definition) {
            $tabs[$channel] = Tab::make($definition['label'])
                ->icon($definition['icon'])
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('channel', $channel));
        }

        return $tabs;
    }
}
