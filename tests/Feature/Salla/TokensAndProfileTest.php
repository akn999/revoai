<?php

use App\Enums\MerchantStatus;
use App\Jobs\FetchMerchantProfile;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Services\TokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('authorize stores the tokens encrypted with their expiry', function () {
    Queue::fake([FetchMerchantProfile::class]);
    $expires = now()->addDays(14)->timestamp;

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(['expires' => $expires])));

    $token = sallaMerchant()->token;
    expect($token->access_token)->toBe('plain-access-token')
        ->and($token->refresh_token)->toBe('plain-refresh-token')
        ->and($token->scope)->toBe('settings.read offline_access')
        ->and($token->expires_at->timestamp)->toBe($expires)
        ->and(DB::table('merchant_tokens')->value('access_token'))->not->toBe('plain-access-token')
        ->and($token->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('EC-18 a second authorize replaces the token row', function () {
    Queue::fake([FetchMerchantProfile::class]);

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 0));
    deliverSalla(sallaEvent('app.store.authorize', authorizeData([
        'access_token' => 'second-access',
        'refresh_token' => 'second-refresh',
        'scope' => 'settings.read orders.read offline_access',
    ]), 1));

    expect(MerchantToken::count())->toBe(1)
        ->and(sallaMerchant()->token)
        ->access_token->toBe('second-access')
        ->scope->toBe('settings.read orders.read offline_access');
});

test('EC-19 an already expired token is saved and refreshed by the next scheduled run', function () {
    Queue::fake([FetchMerchantProfile::class]);
    Http::fake(['accounts.salla.sa/oauth2/token' => Http::response([
        'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1209600,
    ])]);

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(['expires' => now()->subDay()->timestamp])));
    expect(sallaMerchant()->token->expires_at->isPast())->toBeTrue();

    $this->artisan('salla:tokens:refresh')->assertSuccessful();

    $token = sallaMerchant()->token->refresh();
    expect($token->access_token)->toBe('fresh-access')
        ->and($token->refresh_token)->toBe('fresh-refresh')
        ->and($token->expires_at->isFuture())->toBeTrue()
        ->and($token->refreshed_at)->not->toBeNull();
    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'plain-refresh-token');
});

test('the refresh run skips healthy and revoked tokens', function () {
    Http::fake();
    MerchantToken::factory()->create(['merchant_id' => Merchant::factory()->create()->merchant_id]);
    MerchantToken::factory()->expiring()->create([
        'merchant_id' => Merchant::factory()->create()->merchant_id,
        'revoked_at' => now(),
    ]);

    $this->artisan('salla:tokens:refresh')->assertSuccessful();

    Http::assertNothingSent();
});

test('EC-20 two refreshes of the same token call Salla once', function () {
    Http::fake(['accounts.salla.sa/oauth2/token' => Http::response([
        'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1209600,
    ])]);
    $token = MerchantToken::factory()->expiring()->create(['merchant_id' => Merchant::factory()->create()->merchant_id]);
    $workerA = MerchantToken::withoutGlobalScopes()->find($token->id);
    $workerB = MerchantToken::withoutGlobalScopes()->find($token->id);

    $resultA = app(TokenService::class)->refresh($workerA);
    $resultB = app(TokenService::class)->refresh($workerB);

    Http::assertSentCount(1);
    expect($resultA->access_token)->toBe('fresh-access')->and($resultB->access_token)->toBe('fresh-access');
});

test('EC-21 a rejected refresh leaves the token and merchant untouched and raises an alert', function () {
    Log::spy();
    Http::fake(['accounts.salla.sa/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $merchant = Merchant::factory()->active()->create();
    $token = MerchantToken::factory()->expiring()->create(['merchant_id' => $merchant->merchant_id]);

    $this->artisan('salla:tokens:refresh')->assertFailed();

    expect($token->refresh()->access_token)->not->toBe('fresh-access')
        ->and($token->refreshed_at)->toBeNull()
        ->and($merchant->refresh()->status)->toBe(MerchantStatus::Active);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'Salla token refresh failed')->once();
});

test('FR-6 the profile job saves the merchant profile using the merchant token', function () {
    fakeUserInfo();
    $merchant = Merchant::factory()->active()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
    MerchantToken::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT, 'access_token' => 'the-access-token']);

    FetchMerchantProfile::dispatchSync(SALLA_TEST_MERCHANT);

    expect($merchant->refresh())
        ->name->toBe('Cool Store')
        ->domain->toBe('https://cool.salla.sa')
        ->owner_email->toBe('owner@example.com')
        ->mobile->toBe('+966500000000')
        ->profile_synced_at->not->toBeNull();
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer the-access-token'));
});

test('EC-22 user-info for a different merchant is not saved', function () {
    fakeUserInfo(merchantId: 42);
    $merchant = Merchant::factory()->active()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
    MerchantToken::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT]);

    expect(fn () => FetchMerchantProfile::dispatchSync(SALLA_TEST_MERCHANT))
        ->toThrow(RuntimeException::class, 'returned merchant [42]');

    expect($merchant->refresh())->name->toBeNull()->profile_synced_at->toBeNull();
});

test('EC-23 a user-info server error fails the job so it retries and keeps the merchant active', function () {
    Http::fake(['accounts.salla.sa/oauth2/user/info' => Http::response('boom', 503)]);
    $merchant = Merchant::factory()->active()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
    MerchantToken::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT]);

    expect(fn () => FetchMerchantProfile::dispatchSync(SALLA_TEST_MERCHANT))
        ->toThrow(RequestException::class);

    expect($merchant->refresh()->status)->toBe(MerchantStatus::Active);
});

test('EC-24 the profile job exits without calling Salla when the token is gone', function () {
    Http::fake();
    Merchant::factory()->uninstalled()->create(['merchant_id' => SALLA_TEST_MERCHANT]);

    FetchMerchantProfile::dispatchSync(SALLA_TEST_MERCHANT);

    Http::assertNothingSent();
});

test('the profile job refreshes an expired token before calling user-info', function () {
    fakeUserInfo();
    Http::fake([
        'accounts.salla.sa/oauth2/token' => Http::response([
            'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1209600,
        ]),
        'accounts.salla.sa/oauth2/user/info' => Http::response(['data' => [
            'name' => 'Owner', 'merchant' => ['id' => SALLA_TEST_MERCHANT, 'name' => 'Cool Store'],
        ]]),
    ]);
    Merchant::factory()->active()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
    MerchantToken::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT, 'expires_at' => now()->subHour()]);

    FetchMerchantProfile::dispatchSync(SALLA_TEST_MERCHANT);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer fresh-access'));
});
