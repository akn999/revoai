<?php

use App\Enums\BillingCycle;
use App\Enums\MerchantStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\ChangesRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\FeaturesRelationManager;
use App\Filament\Resources\Subscriptions\RelationManagers\PeriodsRelationManager;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use App\Support\CurrentMerchant;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());
});

/**
 * Two merchants whose internal ids deliberately collide with each other's Salla ids
 * (alpha: id 1 / merchant_id 500, beta: id 2 / merchant_id 1), plus a nameless stub.
 *
 * @return array<string, mixed>
 */
function panelFixture(): array
{
    $alpha = Merchant::factory()->create([
        'id' => 1, 'merchant_id' => 500, 'name' => 'Alpha Store', 'email' => 'alpha@example.com',
        'mobile' => '0500000001', 'domain' => 'alpha.example.com', 'owner_name' => 'Aisha Owner',
        'owner_email' => 'aisha@owner-alpha.test', 'status' => MerchantStatus::Active, 'store_type' => 'live',
    ]);
    $beta = Merchant::factory()->create([
        'id' => 2, 'merchant_id' => 1, 'name' => 'Beta Store', 'email' => 'beta@example.com',
        'domain' => 'beta.example.com', 'owner_email' => 'bob@owner-beta.test',
        'status' => MerchantStatus::Uninstalled, 'store_type' => 'demo',
    ]);
    $stub = Merchant::factory()->create(['id' => 3, 'merchant_id' => 900, 'name' => null, 'status' => MerchantStatus::Pending]);

    $localPlan = Plan::factory()->create(['name' => 'Pro Local', 'salla_plan_name' => 'Pro Plan']);

    $alphaPlan = Subscription::factory()->create([
        'merchant_id' => 500, 'plan_id' => $localPlan->id, 'salla_subscription_id' => 1001, 'plan_name' => 'Pro Plan',
        'status' => SubscriptionStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'coupon_code' => 'SAVE10',
        'starts_at' => '2026-06-01 00:00:00', 'ends_at' => now()->addMonth(), 'total' => 23, 'price' => 20,
        'store_type' => 'live', 'currency' => 'SAR', 'meta' => ['categories' => ['Marketing'], 'note' => 'مرحبا'],
    ]);
    $alphaAddon = Subscription::factory()->addon('addon_chat_support')->create([
        'merchant_id' => 500, 'salla_subscription_id' => 1002, 'plan_name' => 'Addon Chat Support', 'quantity' => 3,
        'status' => SubscriptionStatus::Active, 'starts_at' => null, 'total' => 10, 'currency' => 'USD',
        'plan_type' => 'one_time', 'store_type' => 'live',
    ]);
    $alphaOld = Subscription::factory()->create([
        'merchant_id' => 500, 'salla_subscription_id' => 1000, 'plan_name' => 'Starter Plan',
        'status' => SubscriptionStatus::Superseded, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-02-01 00:00:00',
        'total' => 0, 'store_type' => 'development',
    ]);
    $alphaCanceledFuture = Subscription::factory()->create([
        'merchant_id' => 500, 'salla_subscription_id' => 1003, 'plan_name' => 'Legacy Plan',
        'status' => SubscriptionStatus::Canceled, 'starts_at' => '2026-03-01 00:00:00', 'ends_at' => now()->addDays(30), 'total' => 40,
    ]);
    $alphaExpired = Subscription::factory()->create([
        'merchant_id' => 500, 'salla_subscription_id' => 1004, 'plan_name' => 'Expired Plan',
        'status' => SubscriptionStatus::Expired, 'starts_at' => '2026-04-01 00:00:00', 'ends_at' => now()->subDay(), 'total' => 5,
    ]);
    $betaPlan = Subscription::factory()->create([
        'merchant_id' => 1, 'salla_subscription_id' => 2001, 'plan_name' => 'Enterprise Plan',
        'status' => SubscriptionStatus::Canceled, 'billing_cycle' => BillingCycle::Yearly, 'period_months' => 12,
        'starts_at' => '2025-01-01 00:00:00', 'ends_at' => now()->subDay(), 'total' => 500, 'store_type' => 'demo',
        'plan_type' => 'recurring',
    ]);
    $stubTrial = Subscription::factory()->create([
        'merchant_id' => 900, 'salla_subscription_id' => null, 'plan_name' => 'Diamond Plan',
        'status' => SubscriptionStatus::Trial, 'billing_cycle' => BillingCycle::Trial,
        'starts_at' => '2026-06-10 00:00:00', 'ends_at' => now()->addDays(3), 'total' => null, 'price' => null, 'meta' => null,
    ]);

    SubscriptionPeriod::factory()->create(['subscription_id' => $alphaPlan->id, 'kind' => 'start', 'starts_at' => '2026-06-01 00:00:00', 'total' => 23]);
    $renewal = SubscriptionPeriod::factory()->create(['subscription_id' => $alphaPlan->id, 'kind' => 'renewal', 'starts_at' => '2026-07-01 00:00:00', 'total' => 25]);
    $betaPeriod = SubscriptionPeriod::factory()->create(['subscription_id' => $betaPlan->id, 'kind' => 'start', 'starts_at' => '2025-01-01 00:00:00']);

    $featureOne = SubscriptionFeature::factory()->create(['subscription_id' => $alphaPlan->id, 'feature_key' => 'Feature1', 'quantity' => 1]);
    $featureTwo = SubscriptionFeature::factory()->create(['subscription_id' => $alphaPlan->id, 'feature_key' => 'Feature3', 'quantity' => 5]);
    $betaFeature = SubscriptionFeature::factory()->create(['subscription_id' => $betaPlan->id, 'feature_key' => 'OnlyBeta', 'quantity' => 2]);

    $changeStarted = SubscriptionChange::factory()->create(['subscription_id' => $alphaPlan->id, 'merchant_id' => 500, 'change_type' => 'started', 'occurred_at' => '2026-06-01 10:00:00']);
    $changeRenewed = SubscriptionChange::factory()->create(['subscription_id' => $alphaPlan->id, 'merchant_id' => 500, 'change_type' => 'renewed', 'occurred_at' => '2026-07-01 10:00:00']);
    $betaChange = SubscriptionChange::factory()->create(['subscription_id' => $betaPlan->id, 'merchant_id' => 1, 'change_type' => 'canceled', 'occurred_at' => '2026-01-01 10:00:00']);

    return compact(
        'alpha', 'beta', 'stub', 'localPlan', 'alphaPlan', 'alphaAddon', 'alphaOld', 'alphaCanceledFuture', 'alphaExpired',
        'betaPlan', 'stubTrial', 'renewal', 'betaPeriod', 'featureOne', 'featureTwo', 'betaFeature',
        'changeStarted', 'changeRenewed', 'betaChange',
    );
}

function panelAll(array $fixture): array
{
    return [
        $fixture['alphaPlan'], $fixture['alphaAddon'], $fixture['alphaOld'], $fixture['alphaCanceledFuture'],
        $fixture['alphaExpired'], $fixture['betaPlan'], $fixture['stubTrial'],
    ];
}

describe('authorization', function () {
    test('guests are sent to the panel login', function () {
        auth()->logout();

        $this->get('/admin/subscriptions')->assertRedirect('/admin/login');
    });

    test('any registered user can open the list and a view page', function () {
        $fixture = panelFixture();

        $this->get('/admin/subscriptions')->assertOk();
        $this->get('/admin/subscriptions/'.$fixture['alphaPlan']->id)->assertOk();
    });

    test('there are no create or edit pages', function () {
        $fixture = panelFixture();

        $this->get('/admin/subscriptions/create')->assertNotFound();
        $this->get('/admin/subscriptions/'.$fixture['alphaPlan']->id.'/edit')->assertNotFound();
        expect(array_keys(SubscriptionResource::getPages()))->toBe(['index', 'view']);
    });

    test('the resource abilities deny every write', function () {
        $record = panelFixture()['alphaPlan'];

        expect(SubscriptionResource::canViewAny())->toBeTrue()
            ->and(SubscriptionResource::canView($record))->toBeTrue()
            ->and(SubscriptionResource::canCreate())->toBeFalse()
            ->and(SubscriptionResource::canEdit($record))->toBeFalse()
            ->and(SubscriptionResource::canDelete($record))->toBeFalse()
            ->and(SubscriptionResource::canDeleteAny())->toBeFalse();
    });

    test('the list offers only the view action', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->assertTableActionExists('view', record: $fixture['alphaPlan'])
            ->assertTableActionDoesNotExist('edit', record: $fixture['alphaPlan'])
            ->assertTableActionDoesNotExist('delete', record: $fixture['alphaPlan'])
            ->assertActionDoesNotExist('create')
            ->assertTableBulkActionDoesNotExist('delete');
    });

    test('the relation managers are read-only and offer no writing actions', function (string $manager) {
        $fixture = panelFixture();
        $before = DB::table('subscriptions')->count();

        Livewire::test($manager, ['ownerRecord' => $fixture['alphaPlan'], 'pageClass' => ViewSubscription::class])
            ->assertOk()
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist('associate')
            ->assertActionDoesNotExist('attach');

        expect((new $manager)->isReadOnly())->toBeTrue()
            ->and(DB::table('subscriptions')->count())->toBe($before);
    })->with([
        'periods' => PeriodsRelationManager::class,
        'features' => FeaturesRelationManager::class,
        'changes' => ChangesRelationManager::class,
    ]);

    test('viewing and listing never changes any subscription', function () {
        $fixture = panelFixture();
        $snapshot = Subscription::withoutGlobalScopes()->orderBy('id')->get()->toArray();

        Livewire::test(ListSubscriptions::class)->assertOk();
        Livewire::test(ViewSubscription::class, ['record' => $fixture['alphaPlan']->id])->assertOk();

        expect(Subscription::withoutGlobalScopes()->orderBy('id')->get()->toArray())->toBe($snapshot);
    });
});

describe('query scope', function () {
    test('the list ignores the current merchant context', function () {
        $fixture = panelFixture();
        app(CurrentMerchant::class)->set(500);

        Livewire::test(ListSubscriptions::class)
            ->assertCanSeeTableRecords(panelAll($fixture))
            ->assertCountTableRecords(7);
    });

    test('merchant and plan are eager loaded so query count does not grow with rows', function () {
        panelFixture();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListSubscriptions::class)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count(); // warm up one-time queries
        Subscription::factory()->count(5)->create(['plan_id' => null]);
        $withFiveMore = $count();
        Subscription::factory()->count(5)->create(['plan_id' => null]);

        expect($count())->toBe($withFiveMore);
    });
});

describe('table configuration', function () {
    test('the documented columns exist', function (string $column) {
        panelFixture();

        Livewire::test(ListSubscriptions::class)->assertTableColumnExists($column);
    })->with([
        'id', 'merchant.name', 'salla_subscription_id', 'item_type', 'plan_name', 'status', 'billing_cycle', 'quantity',
        'starts_at', 'ends_at', 'total', 'merchant_id', 'merchant.email', 'merchant.owner_email', 'plan.name', 'coupon_code',
    ]);

    test('related and secondary columns are toggleable and hidden by default', function (string $column) {
        panelFixture();

        Livewire::test(ListSubscriptions::class)->assertTableColumnExists(
            $column,
            fn (TextColumn $c): bool => $c->isToggleable() && $c->isToggledHiddenByDefault() && $c->isSearchable(),
        );
    })->with(['merchant.email', 'merchant.owner_email', 'merchant.domain', 'plan.name', 'coupon_code', 'merchant_id', 'item_key']);

    test('status is a sortable badge and total is sortable money', function () {
        panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->assertTableColumnExists('status', fn (TextColumn $c): bool => $c->isBadge() && $c->isSortable())
            ->assertTableColumnExists('total', fn (TextColumn $c): bool => $c->isSortable());
    });

    test('money is formatted in each row currency', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->assertTableColumnFormattedStateSet('total', '$10.00', $fixture['alphaAddon'])
            ->assertTableColumnFormattedStateSet('total', "SAR\u{00A0}23.00", $fixture['alphaPlan']);
    });

    test('every documented filter exists', function (string $filter) {
        panelFixture();

        Livewire::test(ListSubscriptions::class)->assertTableFilterExists($filter);
    })->with([
        'status', 'item_type', 'billing_cycle', 'plan_type', 'store_type', 'merchant', 'merchant_status', 'plan',
        'has_local_plan', 'has_coupon', 'has_end_date', 'entitled_now', 'advanced',
        'starts_at_range', 'ends_at_range', 'renewed_at_range', 'canceled_at_range', 'expired_at_range',
        'superseded_at_range', 'last_event_at_range', 'created_at_range', 'total_range', 'price_range',
    ]);

    test('the default order is newest id first', function () {
        $fixture = panelFixture();
        $expected = collect(panelAll($fixture))->sortByDesc('id')->values()->all();

        Livewire::test(ListSubscriptions::class)->assertCanSeeTableRecords($expected, inOrder: true);
    });
});

describe('search', function () {
    test('each searchable field finds exactly its own rows', function (string $term, array $expectedKeys) {
        $fixture = panelFixture();
        $expected = array_map(fn (string $key) => $fixture[$key], $expectedKeys);
        $others = array_values(array_filter(panelAll($fixture), fn ($record) => ! in_array($record, $expected, true)));

        Livewire::test(ListSubscriptions::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($others);
    })->with([
        'salla subscription id' => ['2001', ['betaPlan']],
        'merchant name' => ['Beta Store', ['betaPlan']],
        'merchant email (column hidden by default)' => ['beta@example.com', ['betaPlan']],
        'merchant domain' => ['alpha.example.com', ['alphaPlan', 'alphaAddon', 'alphaOld', 'alphaCanceledFuture', 'alphaExpired']],
        'owner email' => ['bob@owner-beta.test', ['betaPlan']],
        'plan name' => ['Enterprise Plan', ['betaPlan']],
        'local plan name' => ['Pro Local', ['alphaPlan']],
        'addon item key' => ['addon_chat_support', ['alphaAddon']],
        'coupon code' => ['SAVE10', ['alphaPlan']],
        'salla merchant id of the nameless stub' => ['900', ['stubTrial']],
    ]);

    test('the nameless stub merchant renders a placeholder', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->searchTable('900')
            ->assertCanSeeTableRecords([$fixture['stubTrial']])
            ->assertSee('Unnamed store');
    });

    test('a term that matches nothing shows no rows', function () {
        panelFixture();

        Livewire::test(ListSubscriptions::class)->searchTable('zzz-nothing-matches')->assertCountTableRecords(0);
    });

    test('search and a filter combine as an intersection', function () {
        $fixture = panelFixture();

        $table = Livewire::test(ListSubscriptions::class)
            ->filterTable('status', ['active'])
            ->searchTable('Alpha Store')
            ->assertCanSeeTableRecords([$fixture['alphaPlan'], $fixture['alphaAddon']])
            ->assertCanNotSeeTableRecords([$fixture['alphaOld'], $fixture['alphaCanceledFuture'], $fixture['alphaExpired'], $fixture['betaPlan'], $fixture['stubTrial']]);

        $table->removeTableFilters()->assertCanSeeTableRecords([$fixture['alphaOld'], $fixture['alphaExpired']]);
    });
});

describe('filters', function () {
    test('status accepts several values', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('status', ['active', 'trial'])
            ->assertCanSeeTableRecords([$fixture['alphaPlan'], $fixture['alphaAddon'], $fixture['stubTrial']])
            ->assertCanNotSeeTableRecords([$fixture['alphaOld'], $fixture['alphaCanceledFuture'], $fixture['alphaExpired'], $fixture['betaPlan']]);
    });

    test('type, cycle, plan type and store type narrow the rows', function (string $filter, mixed $value, array $included) {
        $fixture = panelFixture();
        $expected = array_map(fn (string $key) => $fixture[$key], $included);
        $others = array_values(array_filter(panelAll($fixture), fn ($record) => ! in_array($record, $expected, true)));

        Livewire::test(ListSubscriptions::class)
            ->filterTable($filter, $value)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($others);
    })->with([
        'add-ons only' => ['item_type', 'addon', ['alphaAddon']],
        'yearly only' => ['billing_cycle', ['yearly'], ['betaPlan']],
        'one time plan type' => ['plan_type', 'one_time', ['alphaAddon']],
        'demo store type' => ['store_type', ['demo'], ['betaPlan']],
    ]);

    test('the merchant filter uses the Salla merchant id, not the internal id', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('merchant', [1])
            ->assertCanSeeTableRecords([$fixture['betaPlan']])
            ->assertCanNotSeeTableRecords([$fixture['alphaPlan'], $fixture['alphaAddon'], $fixture['alphaOld'], $fixture['alphaCanceledFuture'], $fixture['alphaExpired'], $fixture['stubTrial']]);
    });

    test('the merchant status filter follows the merchant, not the subscription', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('merchant_status', ['status' => 'uninstalled'])
            ->assertCanSeeTableRecords([$fixture['betaPlan']])
            ->assertCanNotSeeTableRecords([$fixture['alphaPlan'], $fixture['stubTrial']]);
    });

    test('local plan filters', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('plan', [$fixture['localPlan']->id])
            ->assertCanSeeTableRecords([$fixture['alphaPlan']])
            ->assertCanNotSeeTableRecords([$fixture['betaPlan'], $fixture['alphaAddon']]);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('has_local_plan', true)
            ->assertCanSeeTableRecords([$fixture['alphaPlan']])
            ->assertCountTableRecords(1);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('has_local_plan', false)
            ->assertCanNotSeeTableRecords([$fixture['alphaPlan']])
            ->assertCountTableRecords(6);
    });

    test('coupon and end date presence', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('has_coupon', true)
            ->assertCanSeeTableRecords([$fixture['alphaPlan']])
            ->assertCountTableRecords(1);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('has_end_date', false)
            ->assertCanSeeTableRecords([$fixture['alphaAddon']])
            ->assertCountTableRecords(1);
    });

    test('currently grants access matches trial, active and canceled-until-later only', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('entitled_now', true)
            ->assertCanSeeTableRecords([$fixture['alphaPlan'], $fixture['alphaAddon'], $fixture['alphaCanceledFuture'], $fixture['stubTrial']])
            ->assertCanNotSeeTableRecords([$fixture['alphaOld'], $fixture['alphaExpired'], $fixture['betaPlan']]);
    });

    test('a date range is inclusive at both bounds and drops rows without a date', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('starts_at_range', ['from' => '2026-04-01', 'until' => '2026-06-01'])
            ->assertCanSeeTableRecords([$fixture['alphaExpired'], $fixture['alphaPlan']])
            ->assertCanNotSeeTableRecords([$fixture['alphaCanceledFuture'], $fixture['alphaOld'], $fixture['stubTrial'], $fixture['betaPlan'], $fixture['alphaAddon']]);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('starts_at_range', ['from' => '2026-06-10'])
            ->assertCanSeeTableRecords([$fixture['stubTrial']])
            ->assertCanNotSeeTableRecords([$fixture['alphaAddon'], $fixture['alphaPlan']]);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('starts_at_range', ['until' => '2025-01-01'])
            ->assertCanSeeTableRecords([$fixture['betaPlan']])
            ->assertCountTableRecords(1);
    });

    test('a number range applies a zero bound and tolerates an inverted range', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('total_range', ['min' => '0', 'max' => '10'])
            ->assertCanSeeTableRecords([$fixture['alphaOld'], $fixture['alphaExpired'], $fixture['alphaAddon']])
            ->assertCanNotSeeTableRecords([$fixture['alphaPlan'], $fixture['betaPlan'], $fixture['stubTrial']]);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('total_range', ['min' => '100', 'max' => '10'])
            ->assertCountTableRecords(0);
    });

    test('the advanced builder reaches merchant, period, feature and change fields', function (array $rules, array $included) {
        $fixture = panelFixture();
        $expected = array_map(fn (string $key) => $fixture[$key], $included);
        $others = array_values(array_filter(panelAll($fixture), fn ($record) => ! in_array($record, $expected, true)));

        Livewire::test(ListSubscriptions::class)
            ->filterTable('advanced', ['rules' => $rules])
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($others);
    })->with([
        'merchant email contains AND status is active' => [
            [
                ['type' => 'merchant_email', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'alpha@'], 'isInverse' => false]],
                ['type' => 'status', 'data' => ['operator' => 'is', 'settings' => ['values' => ['active']], 'isInverse' => false]],
            ],
            ['alphaPlan', 'alphaAddon'],
        ],
        'has a renewal period' => [
            [['type' => 'period_kind', 'data' => ['operator' => 'is', 'settings' => ['value' => 'renewal'], 'isInverse' => false]]],
            ['alphaPlan'],
        ],
        'has feature key' => [
            [['type' => 'feature_key', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'OnlyBeta'], 'isInverse' => false]]],
            ['betaPlan'],
        ],
        'has a canceled change' => [
            [['type' => 'change_type', 'data' => ['operator' => 'is', 'settings' => ['value' => 'canceled'], 'isInverse' => false]]],
            ['betaPlan'],
        ],
        'local plan name' => [
            [['type' => 'local_plan_name', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'Pro Local'], 'isInverse' => false]]],
            ['alphaPlan'],
        ],
        'either of two statuses (OR)' => [
            [[
                'type' => 'or',
                'data' => ['groups' => [
                    ['rules' => [['type' => 'status', 'data' => ['operator' => 'is', 'settings' => ['values' => ['trial']], 'isInverse' => false]]]],
                    ['rules' => [['type' => 'status', 'data' => ['operator' => 'is', 'settings' => ['values' => ['expired']], 'isInverse' => false]]]],
                ]],
            ]],
            ['stubTrial', 'alphaExpired'],
        ],
    ]);

    test('resetting filters restores the full list', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('status', ['trial'])
            ->assertCountTableRecords(1)
            ->resetTableFilters()
            ->assertCanSeeTableRecords(panelAll($fixture))
            ->assertCountTableRecords(7);
    });
});

describe('sorting', function () {
    test('total sorts ascending and descending', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->sortTable('total', 'desc')
            ->assertCanSeeTableRecords([
                $fixture['betaPlan'], $fixture['alphaCanceledFuture'], $fixture['alphaPlan'], $fixture['alphaAddon'],
                $fixture['alphaExpired'], $fixture['alphaOld'], $fixture['stubTrial'],
            ], inOrder: true);
    });

    test('starts_at and status are sortable', function () {
        $fixture = panelFixture();

        Livewire::test(ListSubscriptions::class)
            ->sortTable('starts_at', 'desc')
            ->assertCanSeeTableRecords([
                $fixture['stubTrial'], $fixture['alphaPlan'], $fixture['alphaExpired'], $fixture['alphaCanceledFuture'],
                $fixture['alphaOld'], $fixture['betaPlan'], $fixture['alphaAddon'],
            ], inOrder: true)
            ->sortTable('status')
            ->assertOk();
    });

    test('the store name sorts across the relationship', function () {
        $zed = Merchant::factory()->create(['merchant_id' => 71, 'name' => 'Zed']);
        $ann = Merchant::factory()->create(['merchant_id' => 72, 'name' => 'Ann']);
        $zedSubscription = Subscription::factory()->create(['merchant_id' => $zed->merchant_id]);
        $annSubscription = Subscription::factory()->create(['merchant_id' => $ann->merchant_id]);

        Livewire::test(ListSubscriptions::class)
            ->sortTable('merchant.name')
            ->assertCanSeeTableRecords([$annSubscription, $zedSubscription], inOrder: true)
            ->sortTable('merchant.name', 'desc')
            ->assertCanSeeTableRecords([$zedSubscription, $annSubscription], inOrder: true);
    });
});

describe('view page', function () {
    test('shows the subscription, merchant and charge details', function () {
        $fixture = panelFixture();

        Livewire::test(ViewSubscription::class, ['record' => $fixture['alphaPlan']->id])
            ->assertOk()
            ->assertSee('Alpha Store')
            ->assertSee('500')
            ->assertSee('SAVE10')
            ->assertSee("SAR\u{00A0}23.00")
            ->assertSee('Pro Local');
    });

    test('renders a trial with no Salla id, merchant name or plan', function () {
        $fixture = panelFixture();

        Livewire::test(ViewSubscription::class, ['record' => $fixture['stubTrial']->id])
            ->assertOk()
            ->assertSee('Unnamed store')
            ->assertSee('Not mapped');
    });

    test('the meta entry pretty prints JSON without escaping non-ASCII text', function () {
        $fixture = panelFixture();

        Livewire::test(ViewSubscription::class, ['record' => $fixture['alphaPlan']->id])
            ->assertSee('مرحبا')
            ->assertDontSee('\\u0645', false);
    });

    test('relation managers show only this subscription\'s rows', function () {
        $fixture = panelFixture();
        $page = ['ownerRecord' => $fixture['alphaPlan'], 'pageClass' => ViewSubscription::class];

        Livewire::test(PeriodsRelationManager::class, $page)
            ->assertCanSeeTableRecords(SubscriptionPeriod::where('subscription_id', $fixture['alphaPlan']->id)->get())
            ->assertCanNotSeeTableRecords([$fixture['betaPeriod']])
            ->filterTable('kind', 'renewal')
            ->assertCanSeeTableRecords([$fixture['renewal']])
            ->assertCountTableRecords(1);

        Livewire::test(FeaturesRelationManager::class, $page)
            ->assertCanSeeTableRecords([$fixture['featureOne'], $fixture['featureTwo']], inOrder: true)
            ->assertCanNotSeeTableRecords([$fixture['betaFeature']]);

        Livewire::test(ChangesRelationManager::class, $page)
            ->assertCanSeeTableRecords([$fixture['changeRenewed'], $fixture['changeStarted']], inOrder: true)
            ->assertCanNotSeeTableRecords([$fixture['betaChange']])
            ->filterTable('change_type', ['started'])
            ->assertCanSeeTableRecords([$fixture['changeStarted']])
            ->assertCanNotSeeTableRecords([$fixture['changeRenewed']]);
    });

    test('the changes manager ignores the current merchant context', function () {
        $fixture = panelFixture();
        app(CurrentMerchant::class)->set(1);

        Livewire::test(ChangesRelationManager::class, ['ownerRecord' => $fixture['alphaPlan'], 'pageClass' => ViewSubscription::class])
            ->assertCanSeeTableRecords([$fixture['changeStarted'], $fixture['changeRenewed']]);
    });
});

describe('enums and user', function () {
    test('subscription status labels and colors', function (SubscriptionStatus $status, string $label, string $color) {
        expect($status->getLabel())->toBe($label)->and($status->getColor())->toBe($color);
    })->with([
        [SubscriptionStatus::Trial, 'Trial', 'info'],
        [SubscriptionStatus::Active, 'Active', 'success'],
        [SubscriptionStatus::Canceled, 'Canceled', 'warning'],
        [SubscriptionStatus::Expired, 'Expired', 'danger'],
        [SubscriptionStatus::Superseded, 'Superseded', 'gray'],
    ]);

    test('merchant status labels and colors', function (MerchantStatus $status, string $label, string $color) {
        expect($status->getLabel())->toBe($label)->and($status->getColor())->toBe($color);
    })->with([
        [MerchantStatus::Pending, 'Pending', 'gray'],
        [MerchantStatus::Active, 'Active', 'success'],
        [MerchantStatus::Inactive, 'Inactive', 'warning'],
        [MerchantStatus::Uninstalled, 'Uninstalled', 'danger'],
    ]);

    test('billing cycle labels', function (BillingCycle $cycle, string $label) {
        expect($cycle->getLabel())->toBe($label);
    })->with([
        [BillingCycle::Monthly, 'Monthly'],
        [BillingCycle::Yearly, 'Yearly'],
        [BillingCycle::OneTime, 'One time'],
        [BillingCycle::Trial, 'Trial'],
        [BillingCycle::Custom, 'Custom'],
    ]);

    test('every registered user can access the panel', function () {
        expect(User::factory()->create()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    });
});
