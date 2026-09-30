<?php

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/_probe/ok', fn () => 'ok')->name('probe.ok');
        Route::get('/_probe/missing', fn () => abort(404));
        Route::get('/_probe/boom', fn () => throw new RuntimeException('probe exploded'));
        Route::get('/_probe/excluded/thing', fn () => 'ok');
    });
});

test('a web request is logged with status, duration, route and message', function () {
    $this->get('/_probe/ok')->assertOk();

    expect(ActivityLog::where('action', 'http.request')->sole())
        ->channel->toBe('web')
        ->level->toBe(ActivityLevel::Info)
        ->http_method->toBe('GET')
        ->url->toBe(url('/_probe/ok'))
        ->status_code->toBe(200)
        ->duration_ms->toBeInt()
        ->message->toBe('GET /_probe/ok → 200')
        ->context->toBe(['route' => 'probe.ok']);
});

test('client errors are warnings and server errors are errors', function () {
    $this->get('/_probe/missing')->assertNotFound();
    $this->get('/_probe/boom')->assertServerError();

    expect(ActivityLog::where('action', 'http.request')->where('status_code', 404)->sole()->level)->toBe(ActivityLevel::Warning)
        ->and(ActivityLog::where('action', 'http.request')->where('status_code', 500)->sole()->level)->toBe(ActivityLevel::Error);
});

test('query strings are logged with sensitive values redacted', function () {
    $this->get('/_probe/ok?page=2&token=abc&search=shoes')->assertOk();

    expect(ActivityLog::where('action', 'http.request')->sole()->context['query'])
        ->toBe(['page' => '2', 'token' => '[redacted]', 'search' => 'shoes']);
});

test('excluded paths are not logged', function () {
    config(['activity-log.http.exclude' => ['_probe/excluded/*']]);

    $this->get('/_probe/excluded/thing')->assertOk();
    $this->get('/_probe/ok')->assertOk();

    expect(ActivityLog::where('action', 'http.request')->pluck('url')->all())->toBe([url('/_probe/ok')]);
});

test('request logging can be switched off', function () {
    config(['activity-log.http.enabled' => false]);

    $this->get('/_probe/ok')->assertOk();

    expect(ActivityLog::count())->toBe(0);
});

test('api requests log under the api channel', function () {
    $this->getJson('/api/embedded/status')->assertUnauthorized();

    expect(ActivityLog::where('action', 'http.request')->sole())
        ->channel->toBe('api')
        ->status_code->toBe(401)
        ->level->toBe(ActivityLevel::Warning);
});

test('webhook routes log under the webhook channel via the route map', function () {
    config(['salla.webhook_secret' => 'secret']);

    $this->postJson('/api/webhooks/salla', ['event' => 'app.installed', 'merchant' => 1])->assertUnauthorized();

    expect(ActivityLog::where('action', 'http.request')->sole())
        ->channel->toBe('webhook')
        ->status_code->toBe(401);
});

test('the request body is never stored', function () {
    Route::middleware('web')->post('/_probe/form', fn () => 'ok');

    $this->post('/_probe/form', ['password' => 'hunter2', 'note' => 'private'])->assertOk();

    expect(json_encode(ActivityLog::all()->toArray()))->not->toContain('hunter2')->not->toContain('private');
});
