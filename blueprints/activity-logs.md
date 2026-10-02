# Blueprint: Activity Log viewer (read-only admin)

## Context

The app writes every user action, HTTP request, webhook step, outbound call,
model change, exception and Laravel log line into `activity_logs`
(`App\Models\ActivityLog`, append-only, kept 14 days by default via
`ACTIVITY_LOG_RETENTION_DAYS`). There is no way to look at it yet. This blueprint
adds a Filament resource in the existing `admin` panel
(`app/Providers/Filament/AdminPanelProvider.php`, path `/admin`) to **view, search
and filter** the log, organised by channel tabs, with a detail page per entry.
It sits next to the existing read-only `SubscriptionResource`
(`app/Filament/Resources/Subscriptions/*`) and follows its conventions
(`Tables/`, `Schemas/` subfolders, read-only ability overrides, no create/edit
pages).

## Decisions (settled with the user)

| Topic | Decision |
| --- | --- |
| Editing | **Read-only, append-only.** List + View pages only. No create, edit, delete, bulk actions, export. Retention pruning (`model:prune`) is the only deleter. |
| Access | **Every registered user** who can enter the panel (`User::canAccessPanel()` already returns `true`). |
| Layout | **Tabs per channel** on the list page, plus an "All" and a "Problems" tab. |
| Default view | **Last 24 hours, newest first.** A pre-selected "Period: last 24 hours" filter that the user can change to "Any time" (= everything still retained). |
| Self-logging | The panel's own Livewire update calls are **not logged**: add `livewire*/update` to `activity-log.http.exclude`. Page loads (GET) are still logged. |
| Global (top-bar) search | Off (no `recordTitleAttribute`). Search is the list table's search box. |
| Out of scope | Advanced AND/OR query builder, exports, charts/widgets, live polling, per-entry notes, deleting entries, a "clear log" action, editing retention from the UI. |

### Risk to keep visible

The log contains IP addresses, user agents, e-mails typed into failed logins,
full URLs and exception traces. Fortify self-registration is enabled
(`config/fortify.php` → `Features::registration()`), so with "every registered
user" anyone who signs up can read all of it. Recommendation (not part of this
plan; it is a new business rule): close registration or restrict access with an
allow-list before production.

## Verification notes

- `search-docs` (Laravel Boost) returned HTTP 500 in an earlier planning session
  of this project and was not retried; fallback: Filament Blueprint planning docs
  (v2.4.0) and **installed source** (`filament/filament`, `filament/tables`,
  `filament/schemas`, `filament/query-builder` v5.9.0; `livewire/livewire` v4.4.7).
  The 5.x docs URLs below were **not opened**.
- Verified in installed source: `ListRecords` declares `#[Url(as: 'tab')]`,
  `#[Url(as: 'filters')]`, `#[Url(as: 'search')]`, `#[Url(as: 'sort')]`
  (so a URL can preselect tab/filters/search/sort); `getTabs()` /
  `getDefaultActiveTab()` come from `Filament\Resources\Concerns\HasTabs`;
  `Filament\Schemas\Components\Tabs\Tab` exists; `Filament\Tables\Enums\PaginationMode`
  has `Default`, `Simple`, `Cursor`; `Table::paginationMode()`, `Table::description()`,
  `Table::heading()` exist; `BaseFilter::default(mixed $state)` exists;
  `TernaryFilter::queries(true:, false:, blank:)` exists; `DateTimePicker::seconds(false)`
  exists; `Filament\Support\Contracts\HasLabel/HasColor/HasIcon` exist;
  `Heroicon::Outlined{ClipboardDocumentList,BugAnt,InformationCircle,Bell,ExclamationTriangle,XCircle,Fire,BellAlert,ShieldExclamation,Squares2x2,GlobeAlt,CodeBracket,ArrowsRightLeft,UserCircle,CircleStack,Cog6Tooth,ArrowUpOnSquare,DocumentText}`
  all exist; toggled-hidden columns are still searched; the Livewire update route
  path is `livewire-<hash>/update` (matches the glob `livewire*/update`).
- Test helpers: `pestphp/pest-plugin-livewire` is **not installed** → no global
  `livewire()`. Use `Livewire\Livewire::test(...)` (and
  `Livewire::withQueryParams([...])->test(...)` for URL-driven state). Filament's
  table assertions are Livewire test macros registered by the Filament packages.
- Not verified (the implementing agent must confirm by running the tests below):
  (a) partial `filters=` URL state does not re-apply other filters' defaults — the
  correlation link therefore sets `period=all` explicitly; (b)
  `whereNotNull('context->exception')` behaves the same on SQLite (tests) and
  MySQL (prod).

---

## 1. Commands

Run in this order (all `--no-interaction`; the Filament scaffold commands
occasionally fail on their first invocation with a
`NonInteractiveValidationException` — simply run the same command again):

```
php artisan make:filament-resource ActivityLog --view --no-interaction
php artisan make:test --pest Filament/ActivityLogResourceTest --no-interaction
```

After scaffolding (deliberate deviations):

1. Delete `Pages/CreateActivityLog.php` and `Pages/EditActivityLog.php` and remove
   `create` / `edit` from `getPages()` (keep `index`, `view`).
2. Delete the generated `Schemas/ActivityLogForm.php` and remove `form()` from
   the resource if generated.
3. Delete generated `EditAction`, `DeleteAction`, `DeleteBulkAction`,
   `BulkActionGroup` from the table; remove `CreateAction`/`EditAction` from the
   page header actions.
4. `vendor/bin/pint --dirty --format agent`.

No migrations, no new model, no new factory (reuse `ActivityLogFactory`, which
already has `olderThanDays()`; `MerchantFactory`, `UserFactory`).

## 2. Models and supporting code

No new tables or columns. `activity_logs` columns used: id, channel, action,
level (enum `ActivityLevel`), message, actor_type, actor_id, subject_type,
subject_id, merchant_id, context (json array), correlation_id, ip_address,
user_agent, http_method, url, status_code, duration_ms, created_at
(microsecond precision, no `updated_at`). Indexes already exist on created_at,
(channel, action, created_at), (level, created_at), (merchant_id, created_at),
(actor_type, actor_id), (subject_type, subject_id), correlation_id.

```
Update: App\Enums\ActivityLevel
  Implements: Filament\Support\Contracts\HasLabel, HasColor, HasIcon
  getLabel(): Debug=>'Debug', Info=>'Info', Notice=>'Notice', Warning=>'Warning', Error=>'Error', Critical=>'Critical', Alert=>'Alert', Emergency=>'Emergency'
  getColor(): Debug=>'gray', Info=>'info', Notice=>'primary', Warning=>'warning', Error=>'danger', Critical=>'danger', Alert=>'danger', Emergency=>'danger'
  getIcon(): Debug=>Heroicon::OutlinedBugAnt, Info=>Heroicon::OutlinedInformationCircle, Notice=>Heroicon::OutlinedBell, Warning=>Heroicon::OutlinedExclamationTriangle,
             Error=>Heroicon::OutlinedXCircle, Critical=>Heroicon::OutlinedFire, Alert=>Heroicon::OutlinedBellAlert, Emergency=>Heroicon::OutlinedShieldExclamation
  Add: public static function problems(): array   // [Warning, Error, Critical, Alert, Emergency] — the single definition of "problem" levels
  Imports: Filament\Support\Icons\Heroicon, Filament\Support\Contracts\{HasLabel,HasColor,HasIcon}
```

```
Update: App\Models\ActivityLog
  Add relation: merchant(): BelongsTo   -> belongsTo(Merchant::class, 'merchant_id', 'merchant_id')
                PHPDoc: @return BelongsTo<Merchant, $this>.  (NO database foreign key exists; a row may reference a merchant that has no merchants row — the relation then returns null, which is valid.)
  Add canonical label methods (used by the table, the infolist and their tests — one rule, defined once):
    actorLabel(): string
       actor_type === App\Models\User::class      -> "User #{actor_id}"
       actor_type === 'salla_merchant'            -> "Salla merchant {actor_id}"
       actor_type === 'system'                    -> "System"
       actor_type null                            -> "Guest / none"
       any other non-null actor_type              -> "{actor_type} #{actor_id}"
    subjectLabel(): ?string      -> null when subject_type is null; otherwise class_basename(subject_type)." #{subject_id}"
    subjectUrl(): ?string        -> App\Filament\Resources\Subscriptions\SubscriptionResource::getUrl('view', ['record' => subject_id]) when subject_type === App\Models\Subscription::class, otherwise null
    actorUser(): ?App\Models\User -> App\Models\User::find(actor_id) when actor_type === User::class, otherwise null   (one query; used only on the View page)
```

```
Update: config/activity-log.php
  http.exclude: append 'livewire*/update'   (decision: do not log the panel's own Livewire calls)
```

Single canonical **channel registry** (lives on the resource, used by tabs,
badges and tests): `App\Filament\Resources\ActivityLogs\ActivityLogResource::CHANNELS`

```
const CHANNELS = [
  'web'      => ['label' => 'Web',       'color' => 'primary', 'icon' => Heroicon::OutlinedGlobeAlt],
  'api'      => ['label' => 'API',       'color' => 'info',    'icon' => Heroicon::OutlinedCodeBracket],
  'webhook'  => ['label' => 'Webhooks',  'color' => 'warning', 'icon' => Heroicon::OutlinedArrowsRightLeft],
  'user'     => ['label' => 'Users',     'color' => 'success', 'icon' => Heroicon::OutlinedUserCircle],
  'model'    => ['label' => 'Models',    'color' => 'gray',    'icon' => Heroicon::OutlinedCircleStack],
  'system'   => ['label' => 'System',    'color' => 'danger',  'icon' => Heroicon::OutlinedCog6Tooth],
  'outbound' => ['label' => 'Outbound',  'color' => 'info',    'icon' => Heroicon::OutlinedArrowUpOnSquare],
  'log'      => ['label' => 'App log',   'color' => 'gray',    'icon' => Heroicon::OutlinedDocumentText],
];
```

Rule: a channel not in this registry (a future channel) renders as a gray badge
with its raw name, and is reachable through the **All** tab (and **Problems** if
its level qualifies). Tabs are generated only for registry channels.

## 3. Resource

```
Resource: ActivityLogResource
  Command: php artisan make:filament-resource ActivityLog --view --no-interaction   (see §1 for edits)
  Location: App\Filament\Resources\ActivityLogs\ActivityLogResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview

  Navigation:
    Group: System
    Label: Activity log (navigation + plural), Model label: Log entry
    Icon: Filament\Support\Icons\Heroicon::OutlinedClipboardDocumentList
    Sort: 10
  Imports: Filament\Support\Icons\Heroicon

  Pages (getPages): index => ListActivityLogs, view => ViewActivityLog   (NO create, NO edit)
    App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs
    App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog

  RecordTitleAttribute: none (global search off). Override
    getRecordTitle(?Model $record): string|Htmlable|null  -> "Log entry #{id}" (null-safe)

  Query (override getEloquentQuery):
    return parent::getEloquentQuery()->with('merchant');      // eager load, no N+1
  Form: none.
```

### 3.1 List page — `ListActivityLogs`

Docs: https://filamentphp.com/docs/5.x/resources/listing-records#using-tabs-to-filter-the-records

```
Class: App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs extends Filament\Resources\Pages\ListRecords
  Header actions: none
  getDefaultActiveTab(): string|int|null  -> 'all'
  getTabs(): array<string|int, Filament\Schemas\Components\Tabs\Tab>
    Component: Filament\Schemas\Components\Tabs\Tab   (Docs: https://filamentphp.com/docs/5.x/resources/listing-records#using-tabs-to-filter-the-records)
    'all'      => Tab::make('All')->icon(Heroicon::OutlinedSquares2x2)                                  // no query change
    'problems' => Tab::make('Problems')->icon(Heroicon::OutlinedExclamationTriangle)
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('level', array_map(fn (ActivityLevel $l) => $l->value, ActivityLevel::problems())))
    one tab per key of ActivityLogResource::CHANNELS, in registry order:
                  Tab::make($channel['label'])->icon($channel['icon'])
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('channel', $key))
    NO tab badges (a COUNT per tab on a table that can hold millions of rows is too costly).
  Rule: tab constraint AND every active filter AND search terms. Switching tabs keeps filters and search.
  Imports: Filament\Support\Icons\Heroicon, Illuminate\Database\Eloquent\Builder, App\Enums\ActivityLevel
```

### 3.2 List table — `App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable`

```
Table config:
  Config: ->defaultSort('id', 'desc')                       (id is monotonic with created_at and always indexed)
  Config: ->paginationMode(Filament\Tables\Enums\PaginationMode::Simple)     (no COUNT(*) on a huge table)
  Config: ->paginated([25, 50, 100]), ->defaultPaginationPageOption(50)
  Config: ->filtersLayout(Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible), ->filtersFormColumns(4)
  Config: ->searchPlaceholder('Search message, action, URL, IP, correlation ID, actor, merchant…')
  Config: ->description(fn (): string => 'Entries are kept for '.config('activity-log.retention_days').' days, then deleted automatically. Times are shown in '.config('app.timezone').'.')
          (when retention_days is 0: 'Entries are kept indefinitely.')
  Config: ->emptyStateHeading('No log entries match'), ->emptyStateDescription('Try a longer period, another tab, or clear the filters.')
  Record actions: only Filament\Actions\ViewAction   (Docs: https://filamentphp.com/docs/5.x/actions/view); clicking a row also opens the View page
  Toolbar/bulk actions: none
  No polling.
```

Search rule: the search box ORs across every `->searchable()` column below
(toggled-hidden ones included); multiple words are ANDed (Filament default).
Docs: https://filamentphp.com/docs/5.x/tables/columns/text#searching

**Columns visible by default** (all `Filament\Tables\Columns\TextColumn`,
Docs https://filamentphp.com/docs/5.x/tables/columns/text)

```
Column: created_at
  Config: ->label('When'), ->dateTime('Y-m-d H:i:s'), ->description(fn (ActivityLog $r): string => $r->created_at->diffForHumans()), ->sortable()

Column: level
  Config: ->badge(), ->icon-from-enum (enum ActivityLevel supplies label, color, icon)      // NOT sortable: stored as text names, alphabetical order would mislead

Column: channel
  Config: ->badge(), ->sortable(),
          ->formatStateUsing(fn (string $state): string => ActivityLogResource::CHANNELS[$state]['label'] ?? $state),
          ->color(fn (string $state): string => ActivityLogResource::CHANNELS[$state]['color'] ?? 'gray'),
          ->icon(fn (string $state) => ActivityLogResource::CHANNELS[$state]['icon'] ?? null)

Column: action
  Config: ->searchable(), ->sortable(), ->fontFamily(Filament\Support\Enums\FontFamily::Mono), ->copyable()

Column: message
  Config: ->searchable(), ->limit(90), ->tooltip(fn (ActivityLog $r): ?string => strlen((string) $r->message) > 90 ? $r->message : null), ->wrap(), ->placeholder('—')

Column: status_code
  Config: ->label('Status'), ->badge(), ->sortable(), ->placeholder('—'),
          ->color(fn (?int $state): string => match (true) { $state === null => 'gray', $state >= 500 => 'danger', $state >= 400 => 'warning', $state >= 300 => 'info', default => 'success' })

Column: duration_ms
  Config: ->label('Duration'), ->suffix(' ms'), ->numeric(0), ->sortable(), ->placeholder('—')

Column: actor_type
  Config: ->label('Actor'), ->formatStateUsing(fn ($state, ActivityLog $r): string => $r->actorLabel()), ->searchable(['actor_type', 'actor_id'])

Column: merchant.name
  Config: ->label('Merchant'), ->placeholder('—'), ->description(fn (ActivityLog $r): ?string => $r->merchant_id ? 'ID '.$r->merchant_id : null), ->searchable(), ->sortable()
          (merchant_id is also searchable through the hidden `merchant_id` column below)
```

**Columns hidden by default** (all `->toggleable(isToggledHiddenByDefault: true)`)

```
Column: id                     Config: ->label('ID'), ->sortable()
Column: http_method            Config: ->label('Method'), ->badge(), ->sortable(), ->placeholder('—')
Column: url                    Config: ->searchable(), ->limit(60), ->tooltip(fn (ActivityLog $r): ?string => $r->url), ->placeholder('—')
Column: ip_address             Config: ->label('IP'), ->searchable(), ->sortable(), ->copyable(), ->placeholder('—')
Column: user_agent             Config: ->searchable(), ->limit(50), ->tooltip(fn (ActivityLog $r): ?string => $r->user_agent), ->placeholder('—')
Column: correlation_id         Config: ->label('Correlation ID'), ->searchable(), ->copyable(), ->fontFamily(FontFamily::Mono), ->limit(13), ->tooltip(fn (ActivityLog $r): ?string => $r->correlation_id), ->placeholder('—')
Column: subject_type           Config: ->label('Subject'), ->formatStateUsing(fn ($state, ActivityLog $r): ?string => $r->subjectLabel()), ->url(fn (ActivityLog $r): ?string => $r->subjectUrl()), ->searchable(['subject_type', 'subject_id']), ->placeholder('—')
Column: merchant_id            Config: ->label('Merchant ID'), ->searchable(), ->sortable(), ->placeholder('—')
Column: context                Config: ->searchable(), ->formatStateUsing(fn ($state): ?string => null), ->label('Context (search only)')   // present so the search box also matches inside the JSON context; the cell itself stays empty — read the context on the View page
```

Every entry above: Component `Filament\Tables\Columns\TextColumn`. Imports:
`Filament\Support\Enums\FontFamily`, `App\Models\ActivityLog`,
`App\Filament\Resources\ActivityLogs\ActivityLogResource`.

**Filters** (all combine with AND; each has an indicator). Layout columns: 4.

```
Filter: period                      // DEFAULT ACTIVE: last 24 hours
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\Select::make('value')->label('Period')->options(['15m' => 'Last 15 minutes', '1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', 'all' => 'Any time (everything retained)'])->native(false)->selectablePlaceholder(false)
  Config: ->default(['value' => '24h']),
          ->query(fn (Builder $query, array $data): Builder => $query->when(
              ($data['value'] ?? 'all') !== 'all',
              fn (Builder $query): Builder => $query->where('created_at', '>=', now()->sub(<15 minutes | 1 hour | 24 hours | 7 days>))
          )),
          ->indicateUsing(fn (array $data): ?string => ($data['value'] ?? 'all') === 'all' ? null : 'Period: '.<label>)
  Rule: lower bound inclusive (an entry exactly 24 h old is included, one second older is excluded). "Any time" applies no constraint.

Filter: created_range
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\DateTimePicker::make('from')->seconds(false), Filament\Forms\Components\DateTimePicker::make('until')->seconds(false)
  Config: ->label('Between'), ->query(fn (Builder $query, array $data): Builder => $query
              ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', Carbon::parse($from)->startOfMinute()))
              ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('created_at', '<', Carbon::parse($until)->startOfMinute()->addMinute()))),
          ->indicateUsing(fn (array $data): array => ['Since <from>' / 'Until <until>' for each filled bound])
  Rule: minute precision; `until` includes the whole minute (implemented as `< start of the next minute`, because stored timestamps carry microseconds while SQL bindings do not). Combines with `period` (both apply).

Filter: level
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->options(App\Enums\ActivityLevel::class), ->multiple()

Filter: action
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\TextInput::make('value')->label('Action contains')->placeholder('e.g. salla. or http.request')
  Config: ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['value'] ?? null), fn (Builder $query): Builder => $query->where('action', 'like', '%'.addcslashes(trim($data['value']), '%_\\').'%'))),
          ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? 'Action: '.trim($data['value']) : null)

Filter: http_method
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('HTTP method'), ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE', 'HEAD' => 'HEAD', 'OPTIONS' => 'OPTIONS']), ->multiple()

Filter: status_class
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('HTTP status'), ->multiple(), ->options(['2xx' => '2xx Success', '3xx' => '3xx Redirect', '4xx' => '4xx Client error', '5xx' => '5xx Server error', 'none' => 'No status (not an HTTP entry)']),
          ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['values'] ?? null), fn (Builder $query): Builder => $query->where(function (Builder $query) use ($data): void {
              foreach ($data['values'] as $class) {
                  match ($class) { '2xx' => $query->orWhereBetween('status_code', [200, 299]), '3xx' => ...[300, 399], '4xx' => ...[400, 499], '5xx' => ...[500, 599], 'none' => $query->orWhereNull('status_code') };
              }
          })))
  Rule: selected classes are ORed with each other.

Filter: slower_than
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\TextInput::make('value')->label('Slower than (ms)')->numeric()->minValue(0)
  Config: ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['value'] ?? null), fn (Builder $query): Builder => $query->where('duration_ms', '>=', (int) $data['value']))), ->indicateUsing(... 'Slower than N ms')
  Rule: `filled()` so 0 is applied; entries without a duration are excluded whenever the filter is set; bound inclusive.

Filter: actor_kind
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('Actor'), ->options(['user' => 'Signed-in user', 'salla_merchant' => 'Salla merchant', 'system' => 'System', 'guest' => 'Guest / none']), ->multiple(),
          ->query(... 'user' => actor_type = App\Models\User::class, 'salla_merchant' => actor_type = 'salla_merchant', 'system' => actor_type = 'system', 'guest' => whereNull('actor_type'); selected kinds ORed)

Filter: actor_id
  Component: Filament\Tables\Filters\Filter
  Form: TextInput::make('value')->label('Actor ID')
  Config: ->query(exact match on actor_id when filled), ->indicateUsing('Actor ID: …')
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom

Filter: merchant
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->relationship('merchant', 'name'), ->searchable(['name', 'email', 'domain', 'merchant_id']), ->multiple(),
          ->getOptionLabelFromRecordUsing(fn (Merchant $record): string => ($record->name ?? 'Unnamed store').' ('.$record->merchant_id.')')      // NO ->preload()
  Rule: matches activity_logs.merchant_id = merchants.MERCHANT_ID (the Salla ID), never merchants.id.

Filter: merchant_id
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: TextInput::make('value')->label('Merchant ID')->numeric()
  Config: ->query(exact match on merchant_id when filled)      // for ids that have no merchants row yet (stub not created)

Filter: subject_type
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('Subject type'), ->multiple(), ->options([ App\Models\AppEvent::class => 'Webhook event', Merchant::class => 'Merchant', MerchantToken::class => 'Merchant token', MerchantSetting::class => 'Merchant setting', AppFeedback::class => 'Feedback', Subscription::class => 'Subscription', SubscriptionPeriod::class => 'Subscription period', SubscriptionFeature::class => 'Subscription feature', Plan::class => 'Plan', PlanPrice::class => 'Plan price', PlanFeature::class => 'Plan feature', User::class => 'User' ])
          (all under App\Models)

Filter: subject_id
  Component: Filament\Tables\Filters\Filter
  Form: TextInput::make('value')->label('Subject ID')
  Config: ->query(exact match on subject_id when filled)
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom

Filter: has_exception
  Component: Filament\Tables\Filters\TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
  Config: ->label('Has exception'), ->queries(true: fn (Builder $query): Builder => $query->whereNotNull('context->exception'), false: fn (Builder $query): Builder => $query->whereNull('context->exception'), blank: fn (Builder $query): Builder => $query)

Filter: correlation
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: TextInput::make('value')->label('Correlation ID')
  Config: ->query(exact match on correlation_id when filled), ->indicateUsing('Correlation ID: …')

Filter: ip_address
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: TextInput::make('value')->label('IP address')
  Config: ->query(exact match on ip_address when filled)
```

Imports for the table class: `Filament\Actions\ViewAction`, the filter/column/form
namespaces above, `Illuminate\Database\Eloquent\Builder`,
`Illuminate\Support\Carbon`, `App\Enums\ActivityLevel`, `App\Models\*`.

### 3.3 View page — `ViewActivityLog` + infolist

```
Class: App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog extends Filament\Resources\Pages\ViewRecord    (no header actions; back navigation is the breadcrumb)
Infolist class: App\Filament\Resources\ActivityLogs\Schemas\ActivityLogInfolist   (Docs https://filamentphp.com/docs/5.x/infolists/overview)
Entries: Filament\Infolists\Components\TextEntry (Docs https://filamentphp.com/docs/5.x/infolists/text-entry); sections: Filament\Schemas\Components\Section (Docs https://filamentphp.com/docs/5.x/schemas/sections)

Infolist:
  Columns: 1                                  (sections use ->columns(3): entries are 33% wide, no nested 2x2)

  Section: Summary          ->columns(3)
    level (->badge(); enum supplies label/color/icon), channel (badge, same formatting as column), created_at (->label('When')->dateTime('Y-m-d H:i:s.u')),
    action (->fontFamily(FontFamily::Mono)->copyable()->columnSpan(2)), id (->label('Entry ID')),
    message (->columnSpanFull()->placeholder('—')->prose())

  Section: Who and what     ->columns(3)
    actor (->label('Actor')->state(fn (ActivityLog $r): string => $r->actorLabel())),
    actor_user (->label('User')->state(fn (ActivityLog $r): ?string => ($u = $r->actorUser()) ? $u->name.' ('.$u->email.')' : null)->placeholder('—')),
    subject (->label('Subject')->state(fn (ActivityLog $r): ?string => $r->subjectLabel())->url(fn (ActivityLog $r): ?string => $r->subjectUrl())->placeholder('—')),
    merchant.name (->label('Merchant')->placeholder('Unnamed / unknown')), merchant_id (->label('Merchant ID')->placeholder('—')), merchant.status (->label('Merchant status')->badge()->placeholder('—'))

  Section: Request          ->columns(3)      ->hidden(fn (ActivityLog $r): bool => blank($r->http_method) && blank($r->url) && blank($r->status_code))
    http_method (->badge()), status_code (->badge(), same color rule as the column), duration_ms (->suffix(' ms')->numeric(0)),
    url (->columnSpanFull()->copyable()), ip_address (->label('IP')->copyable()), user_agent (->columnSpan(2))     — all ->placeholder('—')

  Section: Trace            ->columns(1)
    correlation_id (->label('Correlation ID')->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                    ->url(fn (ActivityLog $r): ?string => $r->correlation_id ? ListActivityLogs::getUrl([
                        'tab' => 'all',
                        'filters' => ['correlation' => ['value' => $r->correlation_id], 'period' => ['value' => 'all']],
                    ]) : null),
                    ->helperText / hint: 'Click to list every entry of this request, job or command')
    Rule: the link must land on the All tab with period = Any time, so entries of other channels and older than 24 h appear.

  Section: Exception        ->columns(1)      ->hidden(fn (ActivityLog $r): bool => blank(data_get($r->context, 'exception')))
    exception.class (->label('Class')), exception.message (->label('Message')), exception.file (->label('Location')),
    exception.trace (->label('Trace')->state(fn (ActivityLog $r): string => implode("\n", data_get($r->context, 'exception.trace', [])))->fontFamily(FontFamily::Mono)->copyable())
    (entries read through ->state(fn (ActivityLog $r) => data_get($r->context, 'exception.class')) etc., since context is a JSON array)

  Section: Context          ->columns(1)
    context (->label('Context (JSON)')->state(fn (ActivityLog $r): string => $r->context ? json_encode($r->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '')
             ->fontFamily(FontFamily::Mono)->copyable()->placeholder('No context')->columnSpanFull())
```

## 4. Authorization

```
Resource: ActivityLogResource
  Policy: none (no App\Policies\ActivityLogPolicy)
  Authorization: any authenticated user who passes User::canAccessPanel() (currently: every registered user — decision recorded above)
  Static overrides on ActivityLogResource:
    canViewAny(): true
    canView(Model $record): true
    canCreate(): false
    canEdit(Model $record): false
    canDelete(Model $record): false
    canDeleteAny(): false
  Pages: create/edit routes are not registered → /admin/activity-logs/create and /admin/activity-logs/{id}/edit return 404 (`create` would otherwise match the view route with a non-numeric record: it must 404 as a missing record).
  Model level: ActivityLog::updating() already throws LogicException — a second, independent guard.
  Guests: redirected to /admin/login.
```

## 5. Widgets

None.

## 6. Cross-requirement consistency

**Path A — find entries (tab → filters → search → sort → open → follow correlation)**

- **Rule:** effective query = base (`with('merchant')`) AND tab constraint AND all
  active filters AND search terms. "Problems" = levels
  `ActivityLevel::problems()`. The default active filter set on first load is
  exactly `period = 24h`; "Any time" removes the time bound; the retention prune
  is the only lower limit on what "Any time" can show.
- **Stages:** first load (All tab, period 24h) → switch tab (filters + search
  kept, page reset) → change/clear filters → search → open an entry → click its
  correlation ID → list opens on All tab with `period=all` and only that
  correlation filter.
- **Consistency check (discriminating input):** fixture with an entry exactly
  24 h old and one 24 h + 1 s old (frozen clock), a `warning` in `web` (appears
  in **both** Web and Problems tabs), an `info` in `web` (Web only), a `system`
  `error` (Problems + System), entries sharing one correlation id across `web`,
  `model` and `outbound` where one of them is 3 days old. Following the
  correlation link from the Web tab must return all three (tab reset to All,
  period Any time) even though the Web tab alone would show one.
- **Consistency check (labels):** the actor/subject text seen in the table, in the
  infolist and matched by the search box all derive from
  `ActivityLog::actorLabel()/subjectLabel()` and the raw `actor_type/actor_id`
  columns; searching `System`-like raw values (`system`) and a user id both work.

**Path B — read-only and self-noise**

- **Rule:** the panel can neither create/edit/delete entries, and its own
  Livewire update calls are not logged (`livewire*/update` excluded); a page load
  (GET) still creates one `web` entry.
- **Stages:** render list/view → interact (search/filter/tab) → attempt writes
  (404 routes, ability calls, model update) → confirm log row count unchanged by
  Livewire interactions.
- **Consistency check:** row values and count identical before/after every
  attempt; a request to a route matching `livewire*/update` produces no row,
  while a sibling path (`livewire-x/other`) does.

**Path C — performance (bounded lifecycle: list render)**

- **Rule:** list uses simple pagination (no `COUNT(*)`), tabs have no badges,
  merchant is eager loaded.
- **Consistency check:** rendering the list with N rows and with N+m rows issues
  the same number of queries, and none of them is a `count(*)`.

## 7. Tests

File: `tests/Feature/Filament/ActivityLogResourceTest.php` (Pest; `Feature`
already uses `RefreshDatabase`). Docs:
https://filamentphp.com/docs/5.x/testing/testing-tables and
https://filamentphp.com/docs/5.x/testing/testing-resources.

Prerequisites (verified): Pest 5, `Livewire\Livewire::test()`,
`Livewire::withQueryParams([...])->test(...)`; no `pestphp/pest-plugin-livewire`
(no `livewire()` helper). Setup per test:
`Filament::setCurrentPanel('admin'); $this->actingAs(User::factory()->create());`
and **freeze time** (`$this->travelTo('2026-06-15 12:00:00')`) so period
boundaries are exact. Create fixtures explicitly with
`ActivityLog::factory()->create([... 'created_at' => ...])` (state
`olderThanDays()` exists); expectations are written by hand, not derived from the
implementation. Run:
`php artisan test --compact tests/Feature/Filament/ActivityLogResourceTest.php`.

```
ActivityLogResource:
  Authorization:
    - guest GET /admin/activity-logs redirects to /admin/login
    - a registered user (User::factory()) opens /admin/activity-logs (200) and an entry page (200)
    - GET /admin/activity-logs/create and /admin/activity-logs/{id}/edit return 404
    - canCreate false; canEdit/canDelete($record) false; canDeleteAny false; getPages() keys are exactly [index, view]
    - list has only the 'view' record action (assertTableActionExists('view'); assertTableActionDoesNotExist 'edit' and 'delete'; assertTableBulkActionDoesNotExist 'delete')
    - $entry->update([...]) still throws LogicException (append-only)
    - after rendering list + view + interacting, ActivityLog rows written by Livewire::test are unchanged in count

  Default view and period filter (clock frozen):
    - first load shows the entry exactly 24h old and hides the one 24h+1s old
    - choosing "Any time" (filterTable('period', ['value' => 'all'])) shows both
    - 15m / 1h / 7d options each include the row exactly on the boundary and exclude one second older
    - resetTableFilters restores period = 24h; removeTableFilters shows all rows
    - created_range: 'from' inclusive at minute start, 'until' inclusive through the end of that minute (a row at hh:mm:59 is included, hh:(mm+1):00 excluded); combined with period both apply (AND)

  Tabs:
    - default tab is All and lists every channel
    - Problems shows exactly warning/error/critical/alert/emergency rows across channels and hides debug/info/notice
    - each channel tab (web, api, webhook, user, model, system, outbound, log) shows only its channel; a row with an unregistered channel 'custom' appears in All (and Problems if warning+) but in no channel tab
    - a warning-level web row appears in both Web and Problems; an info web row only in Web
    - switching tab keeps an active filter and the search term
    - no tab exposes a badge

  Component Config:
    - visible columns exist: created_at, level, channel, action, message, status_code, duration_ms, actor_type, merchant.name
    - hidden-by-default and toggleable: id, http_method, url, ip_address, user_agent, correlation_id, subject_type, merchant_id, context
    - level is a badge and NOT sortable; created_at, channel, action, status_code, duration_ms sortable
    - status_code color: 200 success, 302 info, 404 warning, 503 danger, null gray
    - actor cell text: user "User #7", 'system' "System", 'salla_merchant' "Salla merchant 777", null "Guest / none"
    - all documented filters exist (period, created_range, level, action, http_method, status_class, slower_than, actor_kind, actor_id, merchant, merchant_id, subject_type, subject_id, has_exception, correlation, ip_address)
    - list uses simple pagination and page size options 25/50/100

  Search (searchTable, period set to 'all'):
    - message substring, action, url, ip, correlation id, actor id, merchant name, merchant id number
    - text inside the JSON context (column hidden, cell empty) finds the row
    - 'system' finds system-actor rows; a term matching nothing shows zero rows
    - two words are ANDed (word from message + word from url in the same row)

  Filters (assert exact included/excluded identities):
    - level multi-select [error, critical] includes both, excludes warning
    - action contains 'salla.' includes salla.* rows; wildcard characters typed by the user ('%', '_') are matched literally
    - http_method [POST]; status_class 2xx / 4xx / 5xx / none (each boundary: 199 excluded, 200 included, 299 included, 300 excluded); classes are ORed when several selected
    - slower_than 500 includes duration 500 and 501, excludes 499 and null; slower_than 0 includes a row with duration 0 and still excludes null
    - actor_kind user / system / salla_merchant / guest (guest = null actor_type) and ORed multi-select
    - merchant filter with colliding ids (merchants.id=1/merchant_id=500 vs id=2/merchant_id=1): selecting the merchant with merchant_id=1 returns only its rows, not merchant_id=500's
    - merchant_id filter finds rows whose merchant_id has NO merchants row
    - subject_type Subscription vs Merchant; subject_id exact
    - has_exception true → only rows with context.exception; false → only rows without; blank → all
    - correlation exact; ip_address exact
    - filters + tab + search combined return the intersection; each removal widens as expected

  Sorting:
    - default order is id descending (assertCanSeeTableRecords(..., inOrder: true))
    - sortTable('created_at'), 'duration_ms' (null handling as stored), 'status_code' asc/desc with explicit id order asserted

  View page (ViewActivityLog):
    - renders a full HTTP entry: level, channel, action, message, request section (method, url, status, duration, ip, user agent), correlation, context JSON with non-ASCII text unescaped
    - a non-HTTP entry hides the Request section
    - an entry with context.exception shows the Exception section (class, message, location, trace lines); one without hides it
    - a User actor shows name and e-mail (one lookup); a system actor shows "System" and no user
    - a Subscription subject renders a link to that subscription's admin view page; a Merchant subject renders plain text
    - empty context shows "No context"
    - the correlation link URL is ListActivityLogs::getUrl with tab=all, filters[correlation][value]=<id> and filters[period][value]=all; opening it with Livewire::withQueryParams shows all three same-correlation rows (web, model, outbound, one 3 days old) and none of the others, on the All tab

  Performance:
    - list issues the same number of queries for N and N+5 rows (warm-up call first) and none matches /count\(\*\)/i
    - merchant is eager loaded (no per-row merchant query)

  Self-logging exclusion (Path B):
    - a web route registered as 'livewire-abc123/update' (POST) creates no ActivityLog row; 'livewire-abc123/other' creates one
    - config('activity-log.http.exclude') contains 'livewire*/update'

Enums:
  - ActivityLevel: getLabel/getColor/getIcon documented value for every case; problems() returns exactly [Warning, Error, Critical, Alert, Emergency]
  - ActivityLog::actorLabel/subjectLabel/subjectUrl documented output for every case in §2; actorUser returns the User only for User actors
```

Validation tests: not applicable (no forms). Actions tests: only the built-in
`view` (not customised).

## 8. Implementation order (for the implementing agent)

1. `ActivityLevel` (interfaces + `problems()`), `ActivityLog` helper methods and
   relation, config exclude entry (§2).
2. Scaffold + post-scaffold deletions (§1).
3. Resource, `CHANNELS`, table, filters (§3.1–3.2), tabs on `ListActivityLogs`.
4. View page infolist (§3.3).
5. Tests (§7); run them, then `vendor/bin/pint --dirty --format agent` and
   `vendor/bin/phpstan analyse` (project runs Larastan level 7).
6. Ask the user to run the complete suite (`php artisan test --compact`).
7. Visual check in the browser at `/admin/activity-logs` (tabs, default 24 h
   filter, a row's detail page, correlation link).

## 9. Docs index

- Resources: https://filamentphp.com/docs/5.x/resources/overview
- List page tabs: https://filamentphp.com/docs/5.x/resources/listing-records#using-tabs-to-filter-the-records
- Table columns: https://filamentphp.com/docs/5.x/tables/columns/text
- Filters: https://filamentphp.com/docs/5.x/tables/filters/select, /ternary, /custom
- Pagination: https://filamentphp.com/docs/5.x/tables/advanced#pagination
- Infolists: https://filamentphp.com/docs/5.x/infolists/text-entry
- Sections: https://filamentphp.com/docs/5.x/schemas/sections
- Testing: https://filamentphp.com/docs/5.x/testing/testing-tables, /testing-resources
