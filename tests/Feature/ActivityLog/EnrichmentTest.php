<?php

use App\Http\Middleware\LogRequests;
use App\Logging\Activity;
use App\Logging\ActivityContext;
use App\Models\ActivityLog;
use App\Models\Merchant;
use App\Models\User;
use App\Support\CurrentMerchant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

test('the authenticated user becomes the actor', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Activity::info('user', 'clicked');

    expect(ActivityLog::where('action', 'clicked')->sole())
        ->actor_type->toBe(User::class)
        ->actor_id->toBe((string) $user->id);
});

test('an explicit actor wins and bySystem suppresses the current user', function () {
    $this->actingAs(User::factory()->create());
    $other = User::factory()->create();

    Activity::channel('user')->by($other)->info('explicit');
    Activity::channel('system')->bySystem()->info('automated');

    expect(ActivityLog::where('action', 'explicit')->sole()->actor_id)->toBe((string) $other->id)
        ->and(ActivityLog::where('action', 'automated')->sole())->actor_type->toBe('system')->actor_id->toBeNull();
});

test('entries without a user or request have no actor or request data', function () {
    Activity::info('system', 'cron');

    expect(ActivityLog::where('action', 'cron')->sole())
        ->actor_type->toBeNull()
        ->ip_address->toBeNull()
        ->url->toBeNull();
});

test('the current merchant context is attached and an explicit merchant wins', function () {
    app(CurrentMerchant::class)->set(111);

    Activity::info('system', 'implicit');
    Activity::channel('system')->forMerchant(222)->info('explicit');

    expect(ActivityLog::where('action', 'implicit')->sole()->merchant_id)->toBe(111)
        ->and(ActivityLog::where('action', 'explicit')->sole()->merchant_id)->toBe(222);
});

test('entries of one unit of work share a correlation id', function () {
    Activity::info('system', 'first');
    Activity::info('system', 'second');
    Activity::channel('system')->correlatedWith('custom')->info('third');

    $ids = ActivityLog::orderBy('id')->pluck('correlation_id');
    expect($ids[0])->not->toBeNull()->toBe($ids[1])->and($ids[2])->toBe('custom');

    app()->forgetScopedInstances();
    Activity::info('system', 'fourth');

    expect(ActivityLog::where('action', 'fourth')->sole()->correlation_id)->not->toBe($ids[0]);
});

test('a routed request contributes ip, user agent, method and url', function () {
    Route::middleware('web')->get('/_activity-probe', function () {
        Activity::info('user', 'probe.hit');

        return 'ok';
    });

    $this->withHeader('User-Agent', 'ProbeBrowser/1.0')->get('/_activity-probe')->assertOk();

    expect(ActivityLog::where('action', 'probe.hit')->sole())
        ->ip_address->toBe('127.0.0.1')
        ->user_agent->toBe('ProbeBrowser/1.0')
        ->http_method->toBe('GET')
        ->url->toBe(url('/_activity-probe'));
});

test('the request correlation id is returned and reused by every entry of that request', function () {
    Route::middleware('web')->get('/_activity-probe', function () {
        Activity::info('user', 'probe.hit');

        return 'ok';
    });

    $response = $this->withHeader('X-Request-Id', 'req-abc')->get('/_activity-probe');

    $response->assertHeader('X-Request-Id', 'req-abc');
    expect(ActivityLog::pluck('correlation_id')->unique()->all())->toBe(['req-abc']);
});

test('a generated request id is exposed in the response header', function () {
    Route::middleware('web')->get('/_activity-probe', fn () => 'ok');

    $header = $this->get('/_activity-probe')->headers->get('X-Request-Id');

    expect($header)->not->toBeNull()
        ->and(ActivityLog::where('action', 'http.request')->sole()->correlation_id)->toBe($header);
});

test('a verified embedded request is attributed to its merchant', function () {
    Merchant::factory()->active()->create(['merchant_id' => 777]);
    Http::fake(['api.salla.dev/*' => Http::response([
        'success' => true, 'data' => ['merchant_id' => 777, 'user_id' => 1],
    ])]);

    $this->getJson('/api/embedded/status', ['Authorization' => 'Bearer session-token'])->assertOk();

    expect(ActivityLog::where('channel', 'api')->sole())
        ->actor_type->toBe('salla_merchant')
        ->actor_id->toBe('777')
        ->merchant_id->toBe(777);
});

test('the context object is scoped per unit of work', function () {
    $first = app(ActivityContext::class)->correlationId();
    app()->forgetScopedInstances();

    expect(app(ActivityContext::class)->correlationId())->not->toBe($first)
        ->and(LogRequests::class)->toBeString();
});
