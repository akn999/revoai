<?php

use App\Enums\AppEventStatus;
use App\Enums\MerchantStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AppEvent;
use App\Models\Subscription;

function trialData(): array
{
    return ['plan_name' => 'Diamond Plan', 'plan_type' => 'one_time', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20', 'features' => []];
}

test('EC-43 cancelling an active plan keeps access until its end date', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.canceled', planData(), 5));

    $plan = Subscription::sole();
    expect($plan->status)->toBe(SubscriptionStatus::Canceled)
        ->and($plan->canceled_at)->not->toBeNull()
        ->and(Subscription::entitled()->count())->toBe(1)
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Active)
        ->and(sallaMerchant()->currentPlan)->not->toBeNull();
});

test('EC-44 the sweep expires a canceled plan that outlived its end date', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(['end_date' => '2026-07-01']), 0));
    deliverSalla(sallaEvent('app.subscription.canceled', planData(['end_date' => '2026-07-01']), 5));

    $this->travelTo(now()->addMonths(2));
    $this->artisan('salla:subscriptions:sweep')->assertSuccessful();

    $plan = Subscription::sole();
    expect($plan->status)->toBe(SubscriptionStatus::Expired)
        ->and($plan->expired_at)->not->toBeNull()
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Inactive)
        ->and($plan->changes()->latest('id')->first())->change_type->toBe('expired')->app_event_id->toBeNull();
});

test('the sweep leaves open-ended and still-running rows alone', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', addonData(), 1));

    $this->artisan('salla:subscriptions:sweep')->assertSuccessful();

    expect(Subscription::where('status', 'active')->count())->toBe(2)
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Active);
});

test('EC-45 an expired plan does not deactivate a merchant with a live addon', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['subscription_id' => 3000000001]), 1));
    deliverSalla(sallaEvent('app.subscription.expired', planData(), 5));

    expect(Subscription::where('item_type', 'plan')->sole()->status)->toBe(SubscriptionStatus::Expired)
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Active);
});

test('an expired last subscription makes the merchant inactive', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.expired', planData(), 5));

    expect(sallaMerchant()->status)->toBe(MerchantStatus::Inactive)
        ->and(sallaMerchant()->canUseApp())->toBeFalse();
});

test('EC-46 subscribing again after a cancel reactivates the merchant', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.expired', planData(), 5));
    deliverSalla(sallaEvent('app.subscription.started', planData(), 10));

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Active);
});

test('EC-47 renewed arriving before started creates the row and the older started is ignored', function () {
    deliverSalla(sallaEvent('app.subscription.renewed', planData(['renew_date' => '2026-07-01', 'end_date' => '2026-08-01']), 10));
    deliverSalla(sallaEvent('app.subscription.started', planData(['end_date' => '2026-07-01']), 0));

    $plan = Subscription::sole();
    expect($plan->ends_at->toDateString())->toBe('2026-08-01')
        ->and($plan->status)->toBe(SubscriptionStatus::Active)
        ->and(AppEvent::where('event', 'app.subscription.started')->sole()->status)->toBe(AppEventStatus::Ignored);
});

test('EC-48 an expired event older than the applied renewal is ignored', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.renewed', planData(['renew_date' => '2026-07-01', 'end_date' => '2026-08-01']), 10));
    deliverSalla(sallaEvent('app.subscription.expired', planData(), 5));

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(AppEvent::where('event', 'app.subscription.expired')->sole()->status)->toBe(AppEventStatus::Ignored);
});

test('EC-49 cancel and expire for a superseded subscription leave the new plan untouched', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(['subscription_id' => 2000000001, 'end_date' => '2027-06-10']), 5));
    deliverSalla(sallaEvent('app.subscription.canceled', planData(), 10));
    deliverSalla(sallaEvent('app.subscription.expired', planData(), 11));

    expect(Subscription::where('salla_subscription_id', 1510766049)->sole()->status)->toBe(SubscriptionStatus::Superseded)
        ->and(Subscription::where('salla_subscription_id', 2000000001)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(AppEvent::whereIn('event', ['app.subscription.canceled', 'app.subscription.expired'])->pluck('status')->unique()->all())
        ->toBe([AppEventStatus::Ignored]);
});

test('EC-50 an older started for plan A arriving after plan B is stored as superseded', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(['subscription_id' => 2000000002]), 10));
    deliverSalla(sallaEvent('app.subscription.started', planData(['subscription_id' => 1510766049]), 0));

    $planA = Subscription::where('salla_subscription_id', 1510766049)->sole();
    $planB = Subscription::where('salla_subscription_id', 2000000002)->sole();

    expect($planA->status)->toBe(SubscriptionStatus::Superseded)
        ->and($planB->status)->toBe(SubscriptionStatus::Active)
        ->and(Subscription::whereNotNull('current_plan_lock')->pluck('salla_subscription_id')->all())->toBe([2000000002])
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Active);
});

test('EC-51 cancel for a subscription never seen is ignored but kept for audit', function () {
    deliverSalla(sallaEvent('app.subscription.canceled', planData()));

    expect(Subscription::count())->toBe(0)
        ->and(AppEvent::sole()->status)->toBe(AppEventStatus::Ignored);
});

test('EC-52 trial expired and canceled without an open trial are ignored', function () {
    deliverSalla(sallaEvent('app.trial.expired', trialData(), 0));
    deliverSalla(sallaEvent('app.trial.canceled', trialData(), 1));

    expect(AppEvent::pluck('status')->unique()->all())->toBe([AppEventStatus::Ignored])
        ->and(Subscription::count())->toBe(0);
});

test('a trial that expires or is canceled ends access immediately', function (string $event, string $status) {
    deliverSalla(sallaEvent('app.trial.started', trialData(), 0));
    expect(sallaMerchant()->status)->toBe(MerchantStatus::Active);

    deliverSalla(sallaEvent($event, trialData(), 5));

    expect(Subscription::sole()->status->value)->toBe($status)
        ->and(Subscription::entitled()->count())->toBe(0)
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Inactive);
})->with([
    'expired' => ['app.trial.expired', 'expired'],
    'canceled' => ['app.trial.canceled', 'canceled'],
]);
