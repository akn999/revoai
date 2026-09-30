# Blueprint: Subscription Management (read-only admin)

> Deliverable of this plan: write this document, unchanged, to
> `blueprints/subscriptions.md` (the directory does not exist yet). Nothing else
> is created or modified while planning; the feature itself is implemented later
> by following this document.

## Context

The Laravel app receives Salla webhooks and stores every merchant subscription
in `subscriptions` (plus `subscription_periods`, `subscription_features`,
`subscription_changes`). Filament v5.9 is installed with an empty `admin` panel
(`app/Providers/Filament/AdminPanelProvider.php`, path `/admin`, no resources
yet, `app/Filament` does not exist). The owner needs one place to **search and
filter every subscription and every related field**, across all merchants (each
subscription belongs to the merchant who installed the Salla app).

## Decisions (settled with the user)

| Topic | Decision |
| --- | --- |
| Editing | **Read-only.** List page + View page only. No create, edit, delete, bulk actions, export. Webhooks are the source of truth. |
| Panel access | **Every registered user** may open the panel and see all subscriptions. `User` implements `FilamentUser::canAccessPanel()` returning `true`. |
| View page related data | Billing periods, features, change history (three read-only relation managers). |
| Tenancy | None. The panel is platform-wide (all merchants), not tenant-scoped. Filament multi-tenancy is NOT used. |
| Global (top-bar) search | Off (no `recordTitleAttribute`). Search is the list table's search box + per-column search. |
| Out of scope | Merchants resource, activity log UI, exports/imports, widgets, editing plans/prices. |

### Risk to keep visible

Fortify registration is enabled (`config/fortify.php` → `Features::registration()`),
so with "every registered user" anyone who self-registers can read every
merchant's subscription data (emails, phones, prices). Not changed by this plan
(would be a new business rule). Recommend the owner closes registration or
switches to an allow-list before production.

## Verification notes

- `search-docs` (Laravel Boost) returned HTTP 500 while planning. Fallback used:
  Filament Blueprint planning docs (v2.4.0), and **installed source** of
  `filament/filament`, `filament/tables`, `filament/query-builder` v5.9.0 and
  `livewire/livewire` v4.4.7. Docs URLs below are the 5.x URLs to consult;
  they were not opened.
- Verified in installed source: `Resource::canViewAny/canCreate/canEdit/canDelete/canView`
  exist in `Filament\Resources\Resource\Concerns\HasAuthorization`;
  `Filament\Tables\Filters\QueryBuilder` exists and takes constraints from
  `Filament\QueryBuilder\Constraints\*`; `Constraint::relationship($name, $titleAttribute)`
  sets attribute `"{$name}.{$titleAttribute}"` (filters on related-model fields);
  `Filter::schema()` exists (`form()` alias); `Filament\Tables\Enums\FiltersLayout`
  has `AboveContentCollapsible`; toggled-hidden columns are **still searched**
  (`CanSearchRecords` only skips `isHidden()`, not `isToggledHidden()`);
  `RelationManager::isReadOnly()` exists; `Filament\Support\Enums\FontFamily` exists;
  `FilamentUser::canAccessPanel(Panel $panel): bool` exists.
- Test helpers: `pestphp/pest-plugin-livewire` is **not installed**, so the
  global `livewire()` function does not exist. Use `Livewire\Livewire::test(...)`
  (verified `LivewireManager::test`). Filament's table/action assertions
  (`searchTable`, `filterTable`, `assertCanSeeTableRecords`, `assertActionDoesNotExist`, …)
  are Livewire test macros registered by the Filament packages and work with
  `Livewire::test`. No new dependency is needed.
- Not verified (implementing agent must confirm by running the tests below):
  `SelectFilter::relationship('merchant', ...)` filters on the **owner key**
  `merchants.merchant_id` (not `merchants.id`) because `Subscription::merchant()`
  is `belongsTo(Merchant, 'merchant_id', 'merchant_id')`; and
  `Constraint::relationship()` works through `whereHas` for `hasMany` relations.

---

## 1. Commands

Run in order (all with `--no-interaction`):

```
php artisan make:filament-resource Subscription --view --no-interaction
php artisan make:filament-relation-manager SubscriptionResource periods kind --no-interaction
php artisan make:filament-relation-manager SubscriptionResource features feature_key --no-interaction
php artisan make:filament-relation-manager SubscriptionResource changes change_type --no-interaction
php artisan make:test --pest Filament/SubscriptionResourceTest --no-interaction
```

After scaffolding (deliberate deviations from the generated code):

1. Delete `Pages/CreateSubscription.php` and `Pages/EditSubscription.php` and
   remove `create` / `edit` from `SubscriptionResource::getPages()` (keep `index`
   and `view`).
2. Delete the generated `Schemas/SubscriptionForm.php` (the resource has no
   form) and remove `form()` from the resource if it was generated.
3. Delete any generated `EditAction`, `DeleteAction`, `DeleteBulkAction`,
   `BulkActionGroup` from the table.
4. Run `vendor/bin/pint --dirty --format agent`.

No new migrations. No new model. No factory changes (existing
`SubscriptionFactory`, `MerchantFactory`, `PlanFactory`, `SubscriptionPeriodFactory`,
`SubscriptionFeatureFactory`, `SubscriptionChangeFactory` are reused).

## 2. Models

No new tables or columns. Existing relations used (all already defined):

```
Model: App\Models\Subscription   (table subscriptions)
  Relationships used:
    - merchant: belongsTo Merchant, foreign key subscriptions.merchant_id
        -> owner key merchants.merchant_id   (the SALLA merchant ID, NOT merchants.id)
        (defined by trait App\Models\Concerns\BelongsToMerchant)
    - plan: belongsTo Plan (plans.id), nullable (no local mapping is normal)
    - periods: hasMany SubscriptionPeriod
    - features: hasMany SubscriptionFeature
    - changes: hasMany SubscriptionChange
  Scope used: entitled()   (status trial/active, or canceled with ends_at in the future)
  Global scope 'merchant' (BelongsToMerchant): only applies when
    App\Support\CurrentMerchant has an id; the panel must ignore it (see Resource query).
```

Attributes shown (all already exist): id, merchant_id, plan_id,
salla_subscription_id, item_type (`plan`|`addon`), item_key, plan_name,
plan_type (`recurring`|`one_time`), billing_cycle (enum), period_months,
plan_period, quantity, status (enum), starts_at, ends_at, renewed_at,
canceled_at, expired_at, superseded_at, price, price_before_discount,
initialization_cost, tax_rate, tax_value, total, currency, coupon_code,
coupon_amount, store_type (`development`|`demo`|`live`), meta (json),
last_event_at, created_at, updated_at.

Related `Merchant` fields shown: merchant_id, name, email, mobile, domain,
owner_name, owner_email, store_type, status (enum), installed_at, uninstalled_at,
profile_synced_at. `Plan` fields: name, slug.

### Model/enum changes (the only code changes outside `app/Filament`)

```
Update: App\Enums\SubscriptionStatus
  Implements: Filament\Support\Contracts\HasLabel, Filament\Support\Contracts\HasColor
  getLabel(): Trial=>'Trial', Active=>'Active', Canceled=>'Canceled', Expired=>'Expired', Superseded=>'Superseded'
  getColor(): Trial=>'info', Active=>'success', Canceled=>'warning', Expired=>'danger', Superseded=>'gray'

Update: App\Enums\BillingCycle
  Implements: Filament\Support\Contracts\HasLabel
  getLabel(): Monthly=>'Monthly', Yearly=>'Yearly', OneTime=>'One time', Trial=>'Trial', Custom=>'Custom'

Update: App\Enums\MerchantStatus
  Implements: Filament\Support\Contracts\HasLabel, Filament\Support\Contracts\HasColor
  getLabel(): Pending=>'Pending', Active=>'Active', Inactive=>'Inactive', Uninstalled=>'Uninstalled'
  getColor(): Pending=>'gray', Active=>'success', Inactive=>'warning', Uninstalled=>'danger'

Update: App\Models\User
  Implements: Filament\Models\Contracts\FilamentUser
  Method: public function canAccessPanel(\Filament\Panel $panel): bool { return true; }   // decision: every registered user
```

(Signatures: `getLabel(): string|\Illuminate\Contracts\Support\Htmlable|null`,
`getColor(): string|array|null`. Keep existing cases/values untouched.)

## 3. Resources

```
Resource: SubscriptionResource
  Command: php artisan make:filament-resource Subscription --view --no-interaction   (see §1 for edits)
  Location: App\Filament\Resources\Subscriptions\SubscriptionResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview

  Navigation:
    Label: Subscriptions (plural), Subscription (model label)
    Group: Salla
    Icon: Filament\Support\Icons\Heroicon::OutlinedCreditCard
    Sort: 1

  Imports (resource): Filament\Support\Icons\Heroicon

  Pages (getPages): index => ListSubscriptions, view => ViewSubscription   (NO create, NO edit)
    Locations: App\Filament\Resources\Subscriptions\Pages\ListSubscriptions, ...\Pages\ViewSubscription

  RecordTitleAttribute: none (global search off). Override
    getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): string|\Illuminate\Contracts\Support\Htmlable|null
    to return "Subscription #{id}" (null-safe).

  Query (override getEloquentQuery):
    return parent::getEloquentQuery()->withoutGlobalScope('merchant')->with(['merchant', 'plan']);
    Rule: the panel always lists every merchant's rows regardless of App\Support\CurrentMerchant.

  Form: none (no create/edit pages).
```

### 3.1 List table — `App\Filament\Resources\Subscriptions\Tables\SubscriptionsTable`

Table-level configuration:

```
Config: ->defaultSort('id', 'desc')
Config: ->filtersLayout(Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible)
Config: ->filtersFormColumns(4)
Config: ->paginated([10, 25, 50, 100]), ->defaultPaginationPageOption(25)
Config: ->searchPlaceholder('Search subscription ID, store, email, domain, plan, coupon…')
Record actions: only Filament\Actions\ViewAction   (Docs: https://filamentphp.com/docs/5.x/actions/view)
Toolbar/bulk actions: none
Empty state heading: 'No subscriptions yet' (description: 'Subscriptions appear here when Salla sends subscription events.')
```

Search rule: the table search box ORs across **every** column marked
`->searchable()` below (including toggled-hidden ones and related-model
columns); individual column search is not needed. Docs:
https://filamentphp.com/docs/5.x/tables/columns/text#searching

**Columns visible by default**

```
Column: id
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label('ID'), ->sortable()

Column: merchant.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label('Store'), ->searchable(), ->sortable(), ->placeholder('Unnamed store'),
          ->description(fn (Subscription $record): string => 'Merchant ID '.$record->merchant_id)

Column: salla_subscription_id
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label('Salla subscription ID'), ->searchable(), ->sortable(), ->copyable(), ->placeholder('— (trial)')

Column: item_type
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label('Type'), ->badge(), ->sortable(),
          ->formatStateUsing(fn (string $state): string => $state === 'addon' ? 'Add-on' : 'Plan'),
          ->color(fn (string $state): string => $state === 'addon' ? 'info' : 'primary')

Column: plan_name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label('Plan / add-on name'), ->searchable(), ->sortable(), ->placeholder('—')

Column: status
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->badge(), ->sortable()      (enum SubscriptionStatus provides label + color)

Column: billing_cycle
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label('Cycle'), ->badge(), ->sortable()      (enum BillingCycle provides label)

Column: quantity
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->numeric(0), ->sortable()

Column: starts_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->dateTime(), ->sortable(), ->placeholder('—')

Column: ends_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->dateTime(), ->sortable(), ->placeholder('No end date')

Column: total
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->money(fn (Subscription $record): string => $record->currency ?? 'SAR'), ->sortable(), ->placeholder('—')
```

**Columns hidden by default** (all `->toggleable(isToggledHiddenByDefault: true)`; still searchable/sortable as noted)

```
Column: merchant_id                Config: ->label('Salla merchant ID'), ->searchable(), ->sortable(), ->copyable()
Column: merchant.email             Config: ->label('Merchant email'), ->searchable(), ->sortable()
Column: merchant.mobile            Config: ->label('Merchant mobile'), ->searchable()
Column: merchant.domain            Config: ->label('Merchant domain'), ->searchable(), ->sortable()
Column: merchant.owner_name        Config: ->label('Owner name'), ->searchable()
Column: merchant.owner_email       Config: ->label('Owner email'), ->searchable()
Column: merchant.status            Config: ->label('Merchant status'), ->badge(), ->sortable()   (enum MerchantStatus)
Column: merchant.store_type        Config: ->label('Merchant store type'), ->badge(), ->sortable()
Column: plan.name                  Config: ->label('Local plan'), ->searchable(), ->sortable(), ->placeholder('Not mapped')
Column: item_key                   Config: ->label('Item key'), ->searchable(), ->sortable()
Column: plan_type                  Config: ->badge(), ->sortable(), ->formatStateUsing(fn (?string $state): string => $state === 'one_time' ? 'One time' : ucfirst((string) $state)), ->placeholder('—')
Column: period_months              Config: ->label('Period (months)'), ->numeric(0), ->sortable(), ->placeholder('—')
Column: plan_period                Config: ->label('Raw plan_period'), ->searchable(), ->placeholder('—')
Column: store_type                 Config: ->badge(), ->sortable(), ->placeholder('—')
Column: price                      Config: ->money(fn (Subscription $record): string => $record->currency ?? 'SAR'), ->sortable()
Column: price_before_discount      Config: (same money config), ->sortable()
Column: initialization_cost        Config: (same money config), ->sortable()
Column: tax_rate                   Config: ->label('Tax rate (fraction)'), ->numeric(4), ->sortable()
Column: tax_value                  Config: (same money config), ->sortable()
Column: currency                   Config: ->searchable(), ->sortable()
Column: coupon_code                Config: ->searchable(), ->sortable(), ->placeholder('—')
Column: coupon_amount              Config: ->numeric(4), ->sortable(), ->placeholder('—')
Column: renewed_at                 Config: ->dateTime(), ->sortable(), ->placeholder('—')
Column: canceled_at                Config: ->dateTime(), ->sortable(), ->placeholder('—')
Column: expired_at                 Config: ->dateTime(), ->sortable(), ->placeholder('—')
Column: superseded_at              Config: ->dateTime(), ->sortable(), ->placeholder('—')
Column: last_event_at              Config: ->label('Last Salla event'), ->dateTime(), ->sortable()
Column: created_at                 Config: ->dateTime(), ->sortable()
Column: updated_at                 Config: ->dateTime(), ->sortable()
```

Every entry above: Component `Filament\Tables\Columns\TextColumn`, Docs
`https://filamentphp.com/docs/5.x/tables/columns/text`. Relationship columns
(`merchant.*`, `plan.*`) use Filament dot-notation; the query eager-loads
`merchant` and `plan` so there is no N+1.

**Filters** (all combine with AND; the search box ANDs with filters)

```
Filter: status
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->options(App\Enums\SubscriptionStatus::class), ->multiple()

Filter: item_type
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('Type'), ->options(['plan' => 'Plan', 'addon' => 'Add-on'])

Filter: billing_cycle
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->options(App\Enums\BillingCycle::class), ->multiple()

Filter: plan_type
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->options(['recurring' => 'Recurring', 'one_time' => 'One time'])

Filter: store_type
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('Subscription store type'), ->options(['development' => 'Development', 'demo' => 'Demo', 'live' => 'Live']), ->multiple()

Filter: merchant
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->relationship('merchant', 'name'), ->searchable(['name', 'email', 'domain', 'merchant_id']),
          ->getOptionLabelFromRecordUsing(fn (Merchant $record): string => ($record->name ?? 'Unnamed store').' ('.$record->merchant_id.')'),
          ->multiple()        (NO ->preload(): the merchant list can be large; options load by search)
  Rule: filters by subscriptions.merchant_id = merchants.MERCHANT_ID (the Salla ID), never merchants.id.

Filter: merchant_status
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\Select::make('status')->label('Merchant status')->options(App\Enums\MerchantStatus::class)
  Config: ->query(fn (Builder $query, array $data): Builder => $query->when($data['status'], fn (Builder $query, string $status): Builder => $query->whereHas('merchant', fn (Builder $merchant) => $merchant->where('status', $status)))),
          ->indicateUsing(fn (array $data): ?string => $data['status'] ? 'Merchant: '.MerchantStatus::from($data['status'])->getLabel() : null)

Filter: plan
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label('Local plan'), ->relationship('plan', 'name'), ->searchable(), ->preload(), ->multiple()

Filter: has_local_plan
  Component: Filament\Tables\Filters\TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
  Config: ->label('Mapped to a local plan'), ->attribute('plan_id'), ->nullable()

Filter: has_coupon
  Component: Filament\Tables\Filters\TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
  Config: ->label('Has coupon'), ->attribute('coupon_code'), ->nullable()

Filter: has_end_date
  Component: Filament\Tables\Filters\TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
  Config: ->label('Has end date'), ->attribute('ends_at'), ->nullable()

Filter: entitled_now
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Config: ->label('Currently grants access'), ->toggle(), ->query(fn (Builder $query): Builder => $query->entitled())

Filter: {starts_at, ends_at, renewed_at, canceled_at, expired_at, superseded_at, last_event_at, created_at}_range   (8 filters, one private static helper dateRangeFilter(string $column, string $label): Filter)
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\DatePicker::make('from'), Filament\Forms\Components\DatePicker::make('until')
  Config: ->query(fn (Builder $query, array $data): Builder => $query
              ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '>=', $date))
              ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '<=', $date))),
          ->indicateUsing(fn (array $data): array => [... "{$label} from {date}" / "{$label} until {date}" for each filled bound ...])
  Rule: both bounds inclusive; a row with NULL in the column is excluded whenever either bound is set.

Filter: {total, price}_range   (2 filters, helper numberRangeFilter(string $column, string $label): Filter)
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Form: Filament\Forms\Components\TextInput::make('min')->numeric(), Filament\Forms\Components\TextInput::make('max')->numeric()
  Config: ->query(... ->when(filled($data['min'] ?? null), fn ($q) => $q->where($column, '>=', $data['min'])) ->when(filled($data['max'] ?? null), fn ($q) => $q->where($column, '<=', $data['max'])) ...)
  Rule: `filled()` (not truthiness) so a bound of 0 is applied.

Filter: advanced
  Component: Filament\Tables\Filters\QueryBuilder
  Docs: https://filamentphp.com/docs/5.x/tables/filters/query-builder
  Config: ->constraints([...]) ->constraintPickerColumns(2)
  Constraints (namespace Filament\QueryBuilder\Constraints\*, so ANY field, own or related, is filterable with AND/OR groups):
    Subscription fields
      TextConstraint::make('item_key'), TextConstraint::make('plan_name'), TextConstraint::make('plan_period'), TextConstraint::make('currency'), TextConstraint::make('coupon_code'),
      NumberConstraint::make('salla_subscription_id'), NumberConstraint::make('quantity'), NumberConstraint::make('period_months'),
      NumberConstraint::make('price'), NumberConstraint::make('price_before_discount'), NumberConstraint::make('initialization_cost'),
      NumberConstraint::make('tax_rate'), NumberConstraint::make('tax_value'), NumberConstraint::make('total'), NumberConstraint::make('coupon_amount'),
      DateConstraint::make('starts_at'), 'ends_at', 'renewed_at', 'canceled_at', 'expired_at', 'superseded_at', 'last_event_at', 'created_at', 'updated_at' (each ->make(name)),
      SelectConstraint::make('status')->options(SubscriptionStatus::class)->multiple(), SelectConstraint::make('billing_cycle')->options(BillingCycle::class)->multiple(),
      SelectConstraint::make('item_type')->options([...]), SelectConstraint::make('plan_type')->options([...]), SelectConstraint::make('store_type')->options([...])
    Merchant fields (each via ->relationship('merchant', '<column>') and a unique constraint name, e.g. TextConstraint::make('merchant_email')->label('Merchant email')->relationship('merchant', 'email')):
      merchant_id (NumberConstraint), name, email, mobile, domain, owner_name, owner_email (TextConstraint),
      store_type (SelectConstraint), status (SelectConstraint->options(MerchantStatus::class)),
      installed_at, uninstalled_at, profile_synced_at (DateConstraint)
    Local plan fields: TextConstraint::make('plan_name_local')->relationship('plan', 'name'), TextConstraint::make('plan_slug')->relationship('plan', 'slug')
    Child-record fields (match subscriptions having AT LEAST ONE such child):
      periods: SelectConstraint kind (trial/start/renewal), NumberConstraint total, TextConstraint coupon_code, DateConstraint starts_at/ends_at/renew_date  — via ->relationship('periods', '<column>')
      features: TextConstraint feature_key, NumberConstraint quantity — via ->relationship('features', '<column>')
      changes: SelectConstraint change_type (8 values), TextConstraint from_status/to_status, DateConstraint occurred_at — via ->relationship('changes', '<column>')
```

Indicators: default per filter. Persisting filters/search in the session is NOT
enabled.

### 3.2 View page infolist — `App\Filament\Resources\Subscriptions\Schemas\SubscriptionInfolist`

Docs: https://filamentphp.com/docs/5.x/infolists/overview. Namespace for entries:
`Filament\Infolists\Components\{TextEntry}`; layout `Filament\Schemas\Components\Section`.

```
Infolist:
  Columns: 1            (each Section below uses ->columns(3), so entries are 33% wide, never nested 2x2)

  Section: Subscription     Component: Filament\Schemas\Components\Section  Docs: https://filamentphp.com/docs/5.x/schemas/sections   Config: ->columns(3)
    Entries (all Filament\Infolists\Components\TextEntry, Docs https://filamentphp.com/docs/5.x/infolists/text-entry):
      id (->label('ID')), salla_subscription_id (->copyable(), ->placeholder('— (trial)')),
      item_type (badge, same formatting as column), item_key, plan_name (->placeholder('—')),
      plan.name (->label('Local plan'), ->placeholder('Not mapped')), plan_type (badge), status (->badge()),
      billing_cycle (->badge()), period_months, plan_period (->placeholder('—')), quantity (->numeric(0)), store_type (->badge())

  Section: Dates            Config: ->columns(3)
    Entries: starts_at, ends_at (->placeholder('No end date')), renewed_at, canceled_at, expired_at, superseded_at, last_event_at, created_at, updated_at
             (all ->dateTime(), ->placeholder('—'))

  Section: Charge           Config: ->columns(3)
    Entries: price, price_before_discount, initialization_cost, tax_value, total  (->money(fn (Subscription $record): string => $record->currency ?? 'SAR')),
             tax_rate (->numeric(4)), currency, coupon_code (->placeholder('—')), coupon_amount (->numeric(4), ->placeholder('—'))

  Section: Merchant         Config: ->columns(3)
    Entries: merchant_id (->label('Salla merchant ID'), ->copyable()), merchant.name (->label('Store'), ->placeholder('Unnamed store')),
             merchant.email, merchant.mobile, merchant.domain (->url(fn ($state) => $state, shouldOpenInNewTab: true) only if it already starts with http), merchant.owner_name,
             merchant.owner_email, merchant.status (->badge()), merchant.store_type (->badge()),
             merchant.installed_at, merchant.uninstalled_at, merchant.profile_synced_at (->dateTime(), ->placeholder('—'))

  Section: Salla extras (meta)     Config: ->collapsible(), ->collapsed()
    Entry: meta
      Component: Filament\Infolists\Components\TextEntry
      Config: ->label('Raw meta (promotion, balance, categories)'), ->state(fn (Subscription $record): string => json_encode($record->meta ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
              ->fontFamily(Filament\Support\Enums\FontFamily::Mono), ->columnSpanFull()
```

### 3.3 Relation managers (all read-only, shown on the View page)

Common rules for all three: location
`App\Filament\Resources\Subscriptions\RelationManagers\{Name}RelationManager`,
Docs https://filamentphp.com/docs/5.x/resources/managing-relationships;
`isReadOnly(): true`; **no** header actions, record actions, or bulk actions;
`Can create/edit/delete/reorder: no`; no form. Table default: `->paginated([10, 25, 50])`.

```
RelationManager: PeriodsRelationManager
  Command: php artisan make:filament-relation-manager SubscriptionResource periods kind --no-interaction
  Relationship: periods (hasMany SubscriptionPeriod), Title: 'Billing periods'
  Default sort: starts_at desc
  Columns (Filament\Tables\Columns\TextColumn, Docs https://filamentphp.com/docs/5.x/tables/columns/text):
    kind (->badge(), ->sortable(), ->searchable()), starts_at (->dateTime(), ->sortable()), ends_at (->dateTime(), ->sortable(), ->placeholder('—')),
    renew_date (->dateTime(), ->sortable(), ->placeholder('—')), price/tax_value/total (->money('SAR'), ->sortable()), coupon_code (->searchable(), ->placeholder('—')),
    app_event_id (->label('Webhook event ID'), ->sortable()), created_at (->dateTime(), ->sortable(), toggled hidden by default)
  Filters: kind => Filament\Tables\Filters\SelectFilter ->options(['trial' => 'Trial', 'start' => 'Start', 'renewal' => 'Renewal']) (Docs https://filamentphp.com/docs/5.x/tables/filters/select)

RelationManager: FeaturesRelationManager
  Command: php artisan make:filament-relation-manager SubscriptionResource features feature_key --no-interaction
  Relationship: features (hasMany SubscriptionFeature), Title: 'Features'
  Default sort: feature_key asc
  Columns: feature_key (->searchable(), ->sortable()), quantity (->numeric(0), ->sortable())
  Filters: none

RelationManager: ChangesRelationManager
  Command: php artisan make:filament-relation-manager SubscriptionResource changes change_type --no-interaction
  Relationship: changes (hasMany SubscriptionChange), Title: 'Change history'
  Query: ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope('merchant'))   (same rule as the resource query)
  Default sort: occurred_at desc, then id desc
  Columns: change_type (->badge(), ->searchable(), ->sortable()), from_status, to_status (->badge()), from_billing_cycle, to_billing_cycle (->placeholder('—')),
           from_plan_id, to_plan_id (->placeholder('—')), app_event_id (->label('Webhook event ID'), ->placeholder('— (sweep)')),
           occurred_at (->dateTime(), ->sortable()), created_at (->dateTime(), toggled hidden by default)
  Filters: change_type => SelectFilter ->multiple() ->options(started, renewed, canceled, expired, superseded, plan_changed, cycle_changed, quantity_changed with human labels)
```

## 4. Authorization

```
Resource: SubscriptionResource
  Policy: none (no App\Policies\SubscriptionPolicy is created; Filament falls back to the resource's static checks)
  Authorization: any authenticated user who passes User::canAccessPanel() (= every registered user, decision recorded above)
  Overrides on SubscriptionResource (static):
    canViewAny(): true
    canView(Model $record): true
    canCreate(): false
    canEdit(Model $record): false
    canDelete(Model $record): false
    canDeleteAny(): false
  Pages: create/edit routes are NOT registered, so /admin/subscriptions/create and /admin/subscriptions/{id}/edit return 404.
  Relation managers: isReadOnly() true; no mutating actions defined.
  Guests: redirected to the panel login page (`->login()` already configured).
```

Consistency check (read-only path): there is no mutation stage to authorize, so
"denial precedes mutation" reduces to: the routes/actions/abilities do not
exist or return false, and a direct call must leave the row unchanged.

## 5. Widgets

None.

## 6. Cross-requirement consistency

**Path A — search, filter, sort, open a record**

- **Rule:** base query = `Subscription::query()->withoutGlobalScope('merchant')->with(['merchant','plan'])`.
  Search = OR over all `searchable()` columns; filters = AND; merchant identity =
  the Salla `merchant_id` everywhere (column, filter, QueryBuilder constraint,
  infolist, `subscriptions.merchant_id`).
- **Stages:** initial list (all rows, newest id first) → type in search → add
  filter(s) → clear filter → open View page → related tabs show only that
  subscription's rows → back to list.
- **Consistency check (discriminating input):** create Merchant A with internal
  `id=1`, `merchant_id=500` and Merchant B with `id=2`, `merchant_id=1`. A
  wrong implementation that joins on `merchants.id` would return A's rows for a
  "merchant 1" filter; the correct one returns only B's. A merchant stub with
  `name = null` must remain findable by its `merchant_id`, and its row must show
  the placeholder "Unnamed store".
- **Consistency check (context independence):** setting
  `app(CurrentMerchant::class)->set(A)` before listing must NOT hide B's rows.

**Path B — read-only enforcement**

- **Rule:** the panel exposes no way to create, edit, or delete a subscription,
  period, feature or change.
- **Stages:** page render (no create button/edit/delete actions) → direct URL to
  create/edit (404) → resource ability calls (false) → relation manager render
  (no header/record/bulk actions).
- **Consistency check:** row values and row counts are identical before and after
  every attempted mutation.

## 7. Tests

File: `tests/Feature/Filament/SubscriptionResourceTest.php` (Pest; `Feature`
directory already uses `RefreshDatabase`). Docs:
https://filamentphp.com/docs/5.x/testing/testing-tables and
https://filamentphp.com/docs/5.x/testing/testing-resources.

Prerequisites (verified): Pest 5 + `Livewire\Livewire::test()`; no
`pestphp/pest-plugin-livewire` (so no `livewire()` helper — do not use it).
Setup per test: `$this->actingAs(User::factory()->create());`
(`Filament::setCurrentPanel('admin')` if Filament's current panel is not resolved
automatically). Imports: `Livewire\Livewire`, resource page classes, models,
enums. Run: `php artisan test --compact tests/Feature/Filament/SubscriptionResourceTest.php`.
Use independently written expected IDs/values (fixtures created explicitly),
never the implementation's own query.

```
SubscriptionResource:
  Authorization:
    - guest GET /admin/subscriptions redirects to /admin/login
    - a freshly registered user (User::factory()) can open /admin/subscriptions (200) and a view page
    - GET /admin/subscriptions/create returns 404 and GET /admin/subscriptions/{id}/edit returns 404
    - SubscriptionResource::canCreate() is false; canEdit/canDelete($record) are false; canDeleteAny() is false
    - list table has no create/edit/delete/bulk actions: assertActionDoesNotExist for 'create', 'edit', 'delete' on ListSubscriptions (and assert 'view' record action exists)
    - each relation manager (Periods, Features, Changes) renders read-only: no 'create'/'attach'/'associate'/'edit'/'delete' actions, records unchanged after render

  Query scope / tenancy independence:
    - with app(CurrentMerchant::class)->set(merchantA) the list still shows subscriptions of merchant A AND merchant B
    - list eager-loads merchant and plan (no N+1: query count for 25 rows does not grow with rows)

  Component Config:
    - columns exist: id, merchant.name, salla_subscription_id, item_type, plan_name, status, billing_cycle, quantity, starts_at, ends_at, total
    - status column is a badge, sortable; total is sortable and formatted as money in the row's currency (a USD row shows USD, a row with currency SAR shows SAR)
    - hidden-by-default columns (merchant.email, merchant.owner_email, coupon_code, plan.name, …) are toggleable and hidden by default
    - filters exist: status, item_type, billing_cycle, plan_type, store_type, merchant, merchant_status, plan, has_local_plan, has_coupon, has_end_date, entitled_now, advanced, and the 8 date-range + 2 number-range filters

  Search (searchTable):
    - by exact salla_subscription_id returns only that row
    - by merchant name substring returns only that merchant's rows
    - by merchant email while the merchant.email column is toggled hidden by default (proves hidden columns are searched)
    - by merchant Salla ID number, by merchant domain, owner_email, plan_name, local plan name, item_key (addon slug), coupon_code
    - a stub merchant (name null) is found by its merchant_id and its row renders "Unnamed store"
    - a term matching nothing shows zero rows (assertCountTableRecords(0))

  Filters (filterTable), assert exact included/excluded identities, not counts only:
    - status multi-select [active, trial] includes both, excludes canceled/expired/superseded
    - item_type addon excludes plan rows; billing_cycle yearly excludes monthly; plan_type, store_type
    - merchant filter with the Path A fixture (A: id 1 / merchant_id 500, B: id 2 / merchant_id 1): selecting B's merchant_id returns only B's subscriptions
    - merchant_status filter (uninstalled) returns only subscriptions of uninstalled merchants
    - local plan filter; has_local_plan true/false (plan_id null vs set); has_coupon; has_end_date
    - entitled_now includes trial, active and canceled-with-future-ends_at, excludes canceled-with-past-ends_at, expired, superseded
    - starts_at range: from only, until only, both; boundaries inclusive (row exactly on `from` and on `until` included, one day outside excluded); NULL date excluded when a bound is set
    - total range: min 0 is applied (row with total 0 included, negative not present); min > max returns zero rows without error
    - advanced query builder: merchant email "contains" X AND status "is" active; a periods.kind = renewal constraint returns only subscriptions that have a renewal period; a features.feature_key constraint; OR group combining two statuses
    - filters + search combined: filter status=active AND search merchant name returns the intersection only; removing the filter widens results
    - resetTableFilters restores the full list

  Sorting:
    - sortTable('starts_at') asc/desc, 'total', 'status', 'merchant.name' (asc/desc order asserted by explicit id order)
    - default order is id descending

  View page (ViewSubscription):
    - renders for a subscription with all fields populated; shows merchant name, Salla merchant ID, coupon code, formatted total
    - renders for a trial with null salla_subscription_id, null merchant name, null plan (placeholders shown, no errors)
    - relation managers show only THIS subscription's periods/features/changes (asymmetric fixture: two subscriptions with different children; assertCanSeeTableRecords / assertCanNotSeeTableRecords)
    - Changes manager default order is occurred_at desc; change_type filter includes only selected types
    - meta entry shows pretty JSON including a non-ASCII value unescaped

  Read-only attempts:
    - after all attempts above, Subscription row values and Subscription::count() are unchanged

Enums/User:
  - SubscriptionStatus/BillingCycle/MerchantStatus getLabel() returns the documented label for every case; getColor() documented value for every SubscriptionStatus/MerchantStatus case
  - User::canAccessPanel() returns true
```

Validation tests: not applicable (no forms). Actions tests: only `view`
(built-in, not customized → not tested).

## 8. Implementation order (for the implementing agent)

1. Enum + `User` changes (§2). 2. Scaffold commands and post-scaffold deletions
(§1). 3. Resource, table, infolist (§3). 4. Relation managers (§3.3).
5. Tests (§7), run them, then `vendor/bin/pint --dirty --format agent`.
6. Ask the user to run the complete suite (`php artisan test --compact`).
7. Visual check in the browser at `/admin/subscriptions` (create a user with
   `php artisan make:filament-user` or via the registration page).

## 9. Docs index

- Resources: https://filamentphp.com/docs/5.x/resources/overview
- Relation managers: https://filamentphp.com/docs/5.x/resources/managing-relationships
- Table columns: https://filamentphp.com/docs/5.x/tables/columns/text
- Filters: https://filamentphp.com/docs/5.x/tables/filters/select, /ternary, /custom, /query-builder
- Infolists: https://filamentphp.com/docs/5.x/infolists/text-entry
- Sections: https://filamentphp.com/docs/5.x/schemas/sections
- Panel access: https://filamentphp.com/docs/5.x/users/overview
- Testing: https://filamentphp.com/docs/5.x/testing/testing-tables, /testing-resources
