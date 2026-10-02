<?php

namespace App\Filament\Resources\ActivityLogs\Schemas;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ActivityLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Summary')
                    ->columns(3)
                    ->components([
                        TextEntry::make('level')->badge(),
                        TextEntry::make('channel')->badge()
                            ->formatStateUsing(fn (string $state): string => ActivityLogResource::CHANNELS[$state]['label'] ?? $state)
                            ->color(fn (string $state): string => ActivityLogResource::CHANNELS[$state]['color'] ?? 'gray')
                            ->icon(fn (string $state) => ActivityLogResource::CHANNELS[$state]['icon'] ?? null),
                        TextEntry::make('created_at')->label('When')->dateTime('Y-m-d H:i:s.u'),
                        TextEntry::make('action')->fontFamily(FontFamily::Mono)->copyable()->columnSpan(2),
                        TextEntry::make('id')->label('Entry ID'),
                        TextEntry::make('message')->columnSpanFull()->placeholder('—'),
                    ]),

                Section::make('Who and what')
                    ->columns(3)
                    ->components([
                        TextEntry::make('actor')->label('Actor')
                            ->state(fn (ActivityLog $record): string => $record->actorLabel()),
                        TextEntry::make('actor_user')->label('User')
                            ->state(fn (ActivityLog $record): ?string => ($user = $record->actorUser()) ? "{$user->name} ({$user->email})" : null)
                            ->placeholder('—'),
                        TextEntry::make('subject')->label('Subject')
                            ->state(fn (ActivityLog $record): ?string => $record->subjectLabel())
                            ->url(fn (ActivityLog $record): ?string => $record->subjectUrl())
                            ->placeholder('—'),
                        TextEntry::make('merchant.name')->label('Merchant')->placeholder('Unnamed / unknown'),
                        TextEntry::make('merchant_id')->label('Merchant ID')->placeholder('—'),
                        TextEntry::make('merchant.status')->label('Merchant status')->badge()->placeholder('—'),
                    ]),

                Section::make('Request')
                    ->columns(3)
                    ->hidden(fn (ActivityLog $record): bool => blank($record->http_method) && blank($record->url) && blank($record->status_code))
                    ->components([
                        TextEntry::make('http_method')->label('Method')->badge()->placeholder('—'),
                        TextEntry::make('status_code')->label('Status')->badge()
                            ->color(fn (?int $state): string => match (true) {
                                $state === null => 'gray',
                                $state >= 500 => 'danger',
                                $state >= 400 => 'warning',
                                $state >= 300 => 'info',
                                default => 'success',
                            })
                            ->placeholder('—'),
                        TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->numeric(0)->placeholder('—'),
                        TextEntry::make('url')->columnSpanFull()->copyable()->placeholder('—'),
                        TextEntry::make('ip_address')->label('IP')->copyable()->placeholder('—'),
                        TextEntry::make('user_agent')->columnSpan(2)->placeholder('—'),
                    ]),

                Section::make('Trace')
                    ->columns(1)
                    ->components([
                        TextEntry::make('correlation_id')
                            ->label('Correlation ID')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('—')
                            ->helperText('Click to list every entry of this request, job or command.')
                            ->url(fn (ActivityLog $record): ?string => $record->correlation_id
                                ? ListActivityLogs::getUrl([
                                    'tab' => 'all',
                                    'filters' => [
                                        'correlation' => ['value' => $record->correlation_id],
                                        'period' => ['value' => 'all'],
                                    ],
                                ])
                                : null),
                    ]),

                Section::make('Exception')
                    ->columns(1)
                    ->hidden(fn (ActivityLog $record): bool => blank(data_get($record->context, 'exception')))
                    ->components([
                        TextEntry::make('exception_class')->label('Class')
                            ->state(fn (ActivityLog $record): ?string => data_get($record->context, 'exception.class')),
                        TextEntry::make('exception_message')->label('Message')
                            ->state(fn (ActivityLog $record): ?string => data_get($record->context, 'exception.message'))
                            ->placeholder('—'),
                        TextEntry::make('exception_file')->label('Location')
                            ->state(fn (ActivityLog $record): ?string => data_get($record->context, 'exception.file')),
                        TextEntry::make('exception_trace')->label('Trace')
                            ->state(fn (ActivityLog $record): string => implode("\n", (array) data_get($record->context, 'exception.trace', [])))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                    ]),

                Section::make('Context')
                    ->columns(1)
                    ->components([
                        TextEntry::make('context')
                            ->label('Context (JSON)')
                            ->state(fn (ActivityLog $record): string => $record->context
                                ? json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                                : '')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('No context')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
