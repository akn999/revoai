<?php

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use App\Models\AppEvent;
use App\Models\Merchant;
use App\Models\MerchantToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\ThrowingHandler;

function webhookActions(): array
{
    return ActivityLog::where('channel', 'webhook')->where('action', '!=', 'http.request')->orderBy('id')->pluck('action')->all();
}

test('an invalid signature is logged as a warning without storing the event', function () {
    deliverSalla(sallaEvent('app.installed'), 'wrong-secret')->assertUnauthorized();

    expect(ActivityLog::where('action', 'salla.signature_invalid')->sole())
        ->channel->toBe('webhook')
        ->level->toBe(ActivityLevel::Warning)
        ->ip_address->toBe('127.0.0.1')
        ->and(AppEvent::count())->toBe(0);
});

test('a malformed payload is logged', function () {
    deliverSalla(['event' => 'app.installed'])->assertUnprocessable();

    expect(webhookActions())->toBe(['salla.payload_malformed']);
});

test('a processed webhook leaves a received and processed trail tied to the event and merchant', function () {
    deliverSalla(sallaEvent('app.installed', ['app_scopes' => []]))->assertOk();

    $event = AppEvent::sole();
    expect(webhookActions())->toBe(['salla.received', 'salla.processed']);

    foreach (['salla.received', 'salla.processed'] as $action) {
        expect(ActivityLog::where('action', $action)->sole())
            ->subject_type->toBe(AppEvent::class)
            ->subject_id->toBe((string) $event->id)
            ->merchant_id->toBe(SALLA_TEST_MERCHANT)
            ->actor_type->toBe('system');
    }

    $correlations = ActivityLog::whereIn('action', ['salla.received', 'salla.processed', 'http.request'])->pluck('correlation_id')->unique();
    expect($correlations)->toHaveCount(1);
});

test('duplicate deliveries and ignored events are logged', function () {
    $body = sallaEvent('app.something.new');

    deliverSalla($body);
    deliverSalla($body);

    expect(webhookActions())->toBe(['salla.received', 'salla.ignored', 'salla.duplicate']);
});

test('every failed attempt and the final failure are logged', function () {
    config([
        'queue.default' => 'database',
        'salla.handlers' => ['app.boom' => ThrowingHandler::class],
    ]);

    deliverSalla(sallaEvent('app.boom'))->assertOk();
    foreach (range(1, 7) as $attempt) {
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'webhooks']);
    }

    expect(ActivityLog::where('action', 'salla.attempt_failed')->count())->toBe(5);

    $failed = ActivityLog::where('action', 'salla.failed')->sole();
    expect($failed->level)->toBe(ActivityLevel::Error)
        ->and($failed->context['exception']['message'])->toBe('handler exploded')
        ->and($failed->merchant_id)->toBe(SALLA_TEST_MERCHANT);
});

test('token refreshes and the scheduled run are logged', function () {
    Http::fake(['accounts.salla.sa/oauth2/token' => Http::response([
        'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1209600,
    ])]);
    $merchant = Merchant::factory()->active()->create();
    MerchantToken::factory()->expiring()->create(['merchant_id' => $merchant->merchant_id]);

    $this->artisan('salla:tokens:refresh')->assertSuccessful();

    expect(ActivityLog::where('action', 'salla.token_refreshed')->sole()->merchant_id)->toBe($merchant->merchant_id)
        ->and(ActivityLog::where('action', 'salla.tokens_refresh_run')->sole()->context)->toBe(['refreshed' => 1, 'failed' => 0])
        ->and(ActivityLog::where('channel', 'outbound')->sole())->url->toBe('https://accounts.salla.sa/oauth2/token');
});

test('a failed token refresh is logged as an error and the run as a warning', function () {
    Http::fake(['accounts.salla.sa/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $merchant = Merchant::factory()->active()->create();
    MerchantToken::factory()->expiring()->create(['merchant_id' => $merchant->merchant_id]);

    $this->artisan('salla:tokens:refresh')->assertFailed();

    expect(ActivityLog::where('action', 'salla.token_refresh_failed')->sole())
        ->level->toBe(ActivityLevel::Error)
        ->merchant_id->toBe($merchant->merchant_id)
        ->and(ActivityLog::where('action', 'salla.tokens_refresh_run')->sole()->level)->toBe(ActivityLevel::Warning);
});

test('the subscription sweep and event replays are logged', function () {
    $this->artisan('salla:subscriptions:sweep')->assertSuccessful();
    deliverSalla(sallaEvent('app.something.new'));
    $this->artisan('salla:events:replay', ['id' => AppEvent::sole()->id])->assertSuccessful();

    expect(ActivityLog::where('action', 'salla.subscriptions_sweep_run')->sole()->context)->toBe(['expired' => 0])
        ->and(ActivityLog::where('action', 'salla.event_replayed')->sole()->subject_id)->toBe((string) AppEvent::sole()->id);
});

test('the merchant profile sync is logged', function () {
    fakeUserInfo();
    deliverSalla(sallaEvent('app.store.authorize', authorizeData()));

    expect(ActivityLog::where('action', 'salla.profile_synced')->sole()->merchant_id)->toBe(SALLA_TEST_MERCHANT);
});

test('no token or webhook secret ever reaches the activity log', function () {
    fakeUserInfo();
    deliverSalla(sallaEvent('app.store.authorize', authorizeData()))->assertOk();
    deliverSalla(sallaEvent('app.installed'), 'wrong-secret');

    $dump = DB::table('activity_logs')->get()->toJson();

    expect($dump)
        ->not->toContain('plain-access-token')
        ->not->toContain('plain-refresh-token')
        ->not->toContain(SALLA_TEST_SECRET);
});
