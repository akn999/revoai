<?php

use App\Enums\MerchantStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\FetchMerchantProfile;
use App\Models\AppEvent;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\Subscription;
use App\Services\MerchantService;
use App\Sync\InitialProductSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('EC-10 authorize then subscription started leaves one active merchant', function () {
    fakeUserInfo();

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(), 1));

    expect(Merchant::count())->toBe(1)
        ->and(sallaMerchant()->status)->toBe(MerchantStatus::Active)
        ->and(MerchantToken::count())->toBe(1)
        ->and(Subscription::where('item_type', 'plan')->where('status', 'active')->count())->toBe(1);
});

test('EC-11 subscription started before authorize activates a stub that authorize then fills', function () {
    fakeUserInfo();

    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));

    $stub = sallaMerchant();
    expect($stub->status)->toBe(MerchantStatus::Active)
        ->and($stub->name)->toBeNull()
        ->and($stub->token)->toBeNull();

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 1));

    $merchant = sallaMerchant()->refresh();
    expect(Merchant::count())->toBe(1)
        ->and($merchant->id)->toBe($stub->id)
        ->and($merchant->token)->not->toBeNull()
        ->and($merchant->name)->toBe('Cool Store')
        ->and($merchant->profile_synced_at)->not->toBeNull();
});

test('EC-12 resolving the same merchant repeatedly never duplicates the row', function () {
    $event = AppEvent::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT]);

    $first = app(MerchantService::class)->resolve($event);
    $second = app(MerchantService::class)->resolve($event);

    expect($second->id)->toBe($first->id)->and(Merchant::withTrashed()->count())->toBe(1);
});

test('EC-13 trial started before any authorize activates the merchant', function () {
    deliverSalla(sallaEvent('app.trial.started', [
        'plan_name' => 'Diamond Plan',
        'plan_type' => 'one_time',
        'start_date' => '2026-06-15',
        'end_date' => '2026-06-22',
        'features' => [],
        'store_type' => 'development',
    ]));

    expect(sallaMerchant())->status->toBe(MerchantStatus::Active)->store_type->toBe('development')
        ->and(Subscription::count())->toBe(1)
        ->and(Subscription::first())->status->toBe(SubscriptionStatus::Trial);
});

test('EC-14 authorize alone activates the merchant', function () {
    fakeUserInfo();

    deliverSalla(sallaEvent('app.store.authorize', authorizeData()));

    expect(sallaMerchant()->status)->toBe(MerchantStatus::Active)
        ->and(Subscription::count())->toBe(0);
});

test('EC-15 installed after authorize adds install data and keeps the status', function () {
    fakeUserInfo();

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 0));
    deliverSalla(sallaEvent('app.installed', [
        'installation_date' => '2026-06-15 06:06:56',
        'app_scopes' => ['settings.read', 'offline_access'],
        'store_type' => 'development',
    ], 1));

    expect(sallaMerchant())
        ->status->toBe(MerchantStatus::Active)
        ->store_type->toBe('development')
        ->app_scopes->toBe(['settings.read', 'offline_access'])
        ->installed_at->toDateTimeString()->toBe('2026-06-15 06:06:56');
});

test('app updated replaces the granted scopes', function () {
    deliverSalla(sallaEvent('app.installed', ['installation_date' => '2026-06-01 10:00:00', 'app_scopes' => ['settings.read']], 0));
    deliverSalla(sallaEvent('app.updated', ['app_scopes' => ['settings.read', 'orders.read'], 'update_date' => '2026-06-15 12:00:00'], 1));

    expect(sallaMerchant())
        ->app_scopes->toBe(['settings.read', 'orders.read'])
        ->installed_at->toDateTimeString()->toBe('2026-06-01 10:00:00');
});

test('EC-16 an event for a never-seen merchant creates the merchant', function () {
    deliverSalla(sallaEvent('app.updated', ['app_scopes' => []], 0, 555000111));

    expect(Merchant::where('merchant_id', 555000111)->exists())->toBeTrue();
});

test('EC-17 an event for a soft-deleted merchant restores the same row', function () {
    $merchant = Merchant::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
    $merchant->delete();

    deliverSalla(sallaEvent('app.updated', ['app_scopes' => []]));

    expect(Merchant::count())->toBe(1)->and(Merchant::first()->id)->toBe($merchant->id);
});

test('authorize queues one profile fetch on the webhooks queue', function () {
    Queue::fake([FetchMerchantProfile::class, InitialProductSync::class]);

    deliverSalla(sallaEvent('app.store.authorize', authorizeData()));

    Queue::assertPushedOn('webhooks', FetchMerchantProfile::class, fn ($job) => $job->merchantId === SALLA_TEST_MERCHANT);
    Http::assertNothingSent();
});
