<?php

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a new monthly plan creates an active row, one start period and a started change', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData()));

    $subscription = Subscription::sole();

    expect($subscription)
        ->status->toBe(SubscriptionStatus::Active)
        ->billing_cycle->toBe(BillingCycle::Monthly)
        ->item_key->toBe('plan')
        ->salla_subscription_id->toBe(1510766049)
        ->price->toBe('20.00')
        ->total->toBe('23.00')
        ->coupon_code->toBe('SPZGRDFS')
        ->and($subscription->periods()->pluck('kind')->all())->toBe(['start'])
        ->and($subscription->changes()->pluck('change_type')->all())->toBe(['started'])
        ->and($subscription->features()->pluck('quantity', 'feature_key')->all())->toBe(['Feature1' => 1, 'Feature3' => 5]);
});

test('EC-25 to EC-29 the billing cycle is derived from the period dates', function (
    string $planType, ?string $start, ?string $end, string $cycle, ?int $months,
) {
    deliverSalla(sallaEvent('app.subscription.started', planData([
        'plan_type' => $planType, 'plan_period' => '1', 'start_date' => $start, 'end_date' => $end,
    ])));

    expect(Subscription::sole())->billing_cycle->value->toBe($cycle)->period_months->toBe($months);
})->with([
    'EC-25 monthly' => ['recurring', '2026-06-01', '2026-07-01', 'monthly', 1],
    'EC-26 yearly with plan_period 1' => ['recurring', '2026-06-01', '2027-06-01', 'yearly', 12],
    'EC-27 quarterly is custom' => ['recurring', '2026-06-01', '2026-09-01', 'custom', 3],
    'EC-28 January 31 to February 28' => ['recurring', '2026-01-31', '2026-02-28', 'monthly', 1],
    'EC-28 February 28 to March 31' => ['recurring', '2026-02-28', '2026-03-31', 'monthly', 1],
    'EC-29 one time without dates' => ['one_time', null, null, 'one_time', null],
]);

test('EC-29 a one-time addon has no end date and stays entitled until canceled', function () {
    deliverSalla(sallaEvent('app.subscription.started', addonData()));

    $addon = Subscription::sole();
    expect($addon->ends_at)->toBeNull()
        ->and(Subscription::entitled()->count())->toBe(1);

    deliverSalla(sallaEvent('app.subscription.canceled', addonData(), 1));

    expect(Subscription::entitled()->count())->toBe(0);
});

test('EC-30 a renewal extends the same row and adds one renewal period', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(['end_date' => '2026-07-01']), 0));
    deliverSalla(sallaEvent('app.subscription.renewed', planData([
        'renew_date' => '2026-07-01', 'end_date' => '2026-08-01', 'price' => '25.00',
    ]), 5));

    $subscription = Subscription::sole();
    expect($subscription->ends_at->toDateString())->toBe('2026-08-01')
        ->and($subscription->renewed_at->toDateString())->toBe('2026-07-01')
        ->and($subscription->price)->toBe('25.00')
        ->and($subscription->periods()->where('kind', 'renewal')->count())->toBe(1)
        ->and($subscription->changes()->pluck('change_type')->all())->toBe(['started', 'renewed']);
});

test('EC-31 monthly to yearly with a new subscription id supersedes the old row', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData([
        'subscription_id' => 2000000001, 'start_date' => '2026-06-15', 'end_date' => '2027-06-15',
    ]), 5));

    $old = Subscription::where('salla_subscription_id', 1510766049)->sole();
    $new = Subscription::where('salla_subscription_id', 2000000001)->sole();

    expect($old->status)->toBe(SubscriptionStatus::Superseded)
        ->and($old->superseded_at)->not->toBeNull()
        ->and($new)->status->toBe(SubscriptionStatus::Active)->billing_cycle->toBe(BillingCycle::Yearly)
        ->and(Subscription::whereNotNull('current_plan_lock')->count())->toBe(1)
        ->and($old->changes()->pluck('change_type')->all())->toContain('superseded');
});

test('EC-32 monthly to yearly on the same subscription id changes the cycle in place', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(['start_date' => '2026-06-01', 'end_date' => '2026-07-01']), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(['start_date' => '2026-06-15', 'end_date' => '2027-06-15']), 5));

    $subscription = Subscription::sole();
    expect($subscription->billing_cycle)->toBe(BillingCycle::Yearly)
        ->and($subscription->periods()->count())->toBe(2)
        ->and($subscription->changes()->pluck('change_type')->all())->toBe(['started', 'cycle_changed']);
});

test('EC-33 moving to another plan on the same cycle records a plan change', function () {
    $basic = Plan::factory()->create(['salla_plan_name' => 'Basic']);
    $pro = Plan::factory()->create(['salla_plan_name' => 'Pro']);

    deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Basic']), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Pro']), 5));

    $subscription = Subscription::sole();
    expect($subscription->plan_id)->toBe($pro->id)
        ->and($subscription->plan_id)->not->toBe($basic->id)
        ->and($subscription->changes()->latest('id')->first())
        ->change_type->toBe('plan_changed')
        ->from_plan_id->toBe($basic->id)
        ->to_plan_id->toBe($pro->id);
});

test('EC-34 an addon quantity change updates the same row', function () {
    deliverSalla(sallaEvent('app.subscription.started', addonData(['quantity' => 1]), 0));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['quantity' => 3]), 5));

    $addon = Subscription::sole();
    expect($addon->quantity)->toBe(3)
        ->and($addon->changes()->pluck('change_type')->all())->toBe(['started', 'quantity_changed']);
});

test('EC-35 an addon bought while a plan is active is a separate entitled row', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['subscription_id' => 3000000001]), 1));

    expect(Subscription::count())->toBe(2)
        ->and(Subscription::where('item_type', 'plan')->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(Subscription::entitled()->count())->toBe(2);
});

test('EC-36 a plan and two addons sharing one subscription id are told apart by item key', function () {
    $shared = ['subscription_id' => 1510766049];

    deliverSalla(sallaEvent('app.subscription.started', planData($shared), 0));
    deliverSalla(sallaEvent('app.subscription.started', addonData($shared + ['item_slug' => 'addon_chat_support']), 1));
    deliverSalla(sallaEvent('app.subscription.started', addonData($shared + ['item_slug' => 'addon_extra_seats']), 2));

    expect(Subscription::pluck('item_key')->sort()->values()->all())
        ->toBe(['addon_chat_support', 'addon_extra_seats', 'plan']);
});

test('EC-37 a trial followed by a paid plan supersedes the trial', function () {
    deliverSalla(sallaEvent('app.trial.started', [
        'plan_name' => 'Diamond Plan', 'plan_type' => 'one_time', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20', 'features' => [],
    ], 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(), 5));

    $trial = Subscription::whereNull('salla_subscription_id')->sole();
    $paid = Subscription::whereNotNull('salla_subscription_id')->sole();

    expect($trial->status)->toBe(SubscriptionStatus::Superseded)
        ->and($paid->status)->toBe(SubscriptionStatus::Active)
        ->and(Subscription::whereNotNull('current_plan_lock')->count())->toBe(1);
});

test('a trial event is ignored while a paid plan is active', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.trial.started', ['plan_name' => 'Diamond Plan', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20'], 5));

    expect(Subscription::count())->toBe(1)->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Active);
});

test('EC-38 MySQL-level guard rejects a second open plan row', function () {
    $merchant = Merchant::factory()->active()->create();
    Subscription::factory()->create(['merchant_id' => $merchant->merchant_id]);

    expect(fn () => Subscription::factory()->create(['merchant_id' => $merchant->merchant_id]))
        ->toThrow(UniqueConstraintViolationException::class);

    Subscription::factory()->status(SubscriptionStatus::Canceled)->create(['merchant_id' => $merchant->merchant_id]);
    Subscription::factory()->addon()->create(['merchant_id' => $merchant->merchant_id]);

    expect(Subscription::count())->toBe(3);
});

test('EC-39 a plan without a name or local mapping stays active with no plan id', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => null])));

    expect(Subscription::sole())->plan_id->toBeNull()->status->toBe(SubscriptionStatus::Active);
});

test('EC-40 an empty features array creates no feature rows', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(['features' => []])));

    expect(Subscription::sole()->features()->count())->toBe(0);
});

test('EC-41 a payload without features copies the matched plan defaults', function () {
    $plan = Plan::factory()->create(['salla_plan_name' => 'Pro']);
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature_key' => 'orders', 'quantity' => 100]);
    $body = sallaEvent('app.subscription.started', planData(['plan_name' => 'Pro']));
    unset($body['data']['features']);

    deliverSalla($body);

    expect(Subscription::sole()->features()->pluck('quantity', 'feature_key')->all())->toBe(['orders' => 100]);
});

test('EC-42 missing coupon, string balance and mixed number types are stored correctly', function () {
    $body = sallaEvent('app.subscription.started', planData([
        'price' => 20, 'tax_value' => 3, 'total' => '23.00', 'initialization_cost' => '10', 'tax' => 0.15,
        'subscription_balance' => 'null',
    ]));
    unset($body['data']['coupon']);

    deliverSalla($body);

    $subscription = Subscription::sole();
    expect($subscription)
        ->coupon_code->toBeNull()
        ->coupon_amount->toBeNull()
        ->price->toBe('20.00')
        ->tax_value->toBe('3.00')
        ->total->toBe('23.00')
        ->and((float) $subscription->tax_rate)->toBe(0.15)
        ->and($subscription->meta['subscription_balance'])->toBe('null');
});

test('an addon payload with a matching local plan is mapped by its slug', function () {
    $addon = Plan::factory()->addon()->create(['salla_item_slug' => 'addon_chat_support']);

    deliverSalla(sallaEvent('app.subscription.started', addonData()));

    expect(Subscription::sole()->plan_id)->toBe($addon->id);
});

test('feature entitlement sums quantities across every entitled row', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['subscription_id' => 3000000001]), 1));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['subscription_id' => 3000000002, 'item_slug' => 'addon_old']), 2));
    deliverSalla(sallaEvent('app.subscription.expired', addonData(['subscription_id' => 3000000002, 'item_slug' => 'addon_old']), 3));

    expect(sallaMerchant()->featureQuantity('Feature3'))->toBe(10)
        ->and(sallaMerchant()->featureQuantity('Feature1'))->toBe(2)
        ->and(sallaMerchant()->featureQuantity('Unknown'))->toBe(0);
});

test('replaying subscription rows from the database keeps one period per start', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData()));

    expect(SubscriptionPeriod::count())->toBe(1)
        ->and(SubscriptionChange::count())->toBe(1)
        ->and(DB::table('subscriptions')->whereNotNull('current_plan_lock')->count())->toBe(1);
});
