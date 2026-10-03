<?php

namespace App\Filament\Pages;

use App\Models\UsageLedger;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Usage report';

    protected string $view = 'filament.pages.table-page';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => UsageLedger::query()->withoutGlobalScopes()
                ->selectRaw('min(id) as id, merchant_id, provider_model_id, feature, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(images) as images, sum(provider_cost_usd) as cost_usd, sum(credits_charged) as credits')
                ->groupBy('merchant_id', 'provider_model_id', 'feature'))
            ->columns([
                TextColumn::make('merchant_id')->label('Merchant')->searchable()->sortable(),
                TextColumn::make('feature')->badge()->sortable(),
                TextColumn::make('provider_model_id')->label('Model')->sortable(),
                TextColumn::make('calls')->numeric()->sortable(),
                TextColumn::make('input_tokens')->label('Tokens in')->numeric()->sortable(),
                TextColumn::make('output_tokens')->label('Tokens out')->numeric()->sortable(),
                TextColumn::make('images')->numeric()->sortable(),
                TextColumn::make('cost_usd')->label('Provider cost')->money('USD')->sortable(),
                TextColumn::make('credits')->label('Credits charged')->numeric()->sortable(),
            ])
            ->filters([
                SelectFilter::make('feature')->options(fn () => UsageLedger::query()->withoutGlobalScopes()->distinct()->orderBy('feature')->pluck('feature', 'feature')->all()),
                Filter::make('period')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '<', now()->parse($date)->addDay()->toDateString()))),
            ])
            ->defaultSort('credits', 'desc')
            ->paginated([10, 25, 50]);
    }
}
