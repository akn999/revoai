<?php

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

test('outbound calls are logged with method, url, status and duration but without the query values', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    Http::get('https://api.example.com/things?token=secret-value&page=2');

    expect(ActivityLog::where('channel', 'outbound')->sole())
        ->action->toBe('http.request')
        ->level->toBe(ActivityLevel::Info)
        ->http_method->toBe('GET')
        ->url->toBe('https://api.example.com/things')
        ->status_code->toBe(200)
        ->duration_ms->toBeInt()
        ->actor_type->toBe('system')
        ->ip_address->toBeNull()
        ->context->toBe(['query_keys' => ['token', 'page']])
        ->and(json_encode(ActivityLog::all()->toArray()))->not->toContain('secret-value');
});

test('failed outbound responses are logged as errors', function () {
    Http::fake(['api.example.com/*' => Http::response('down', 503)]);

    Http::post('https://api.example.com/things');

    expect(ActivityLog::where('channel', 'outbound')->sole())
        ->level->toBe(ActivityLevel::Error)
        ->status_code->toBe(503)
        ->http_method->toBe('POST');
});

test('connection failures are logged with the exception and no status', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => Http::get('https://api.example.com/things'))->toThrow(ConnectionException::class);

    $entry = ActivityLog::where('channel', 'outbound')->sole();
    expect($entry->level)->toBe(ActivityLevel::Error)
        ->and($entry->status_code)->toBeNull()
        ->and($entry->context['exception']['message'])->toBe('timed out');
});

test('an outbound call made inside a request is not attributed to the inbound client', function () {
    Http::fake(['api.example.com/*' => Http::response([], 200)]);
    Route::middleware('web')->get('/_probe/outbound', function () {
        Http::get('https://api.example.com/things');

        return 'ok';
    });

    $this->get('/_probe/outbound')->assertOk();

    $outbound = ActivityLog::where('channel', 'outbound')->sole();
    $inbound = ActivityLog::where('channel', 'web')->sole();
    expect($outbound->ip_address)->toBeNull()->and($outbound->correlation_id)->toBe($inbound->correlation_id);
});

test('outbound logging can be switched off', function () {
    config(['activity-log.outbound.enabled' => false]);
    Http::fake();

    Http::get('https://api.example.com/things');

    expect(ActivityLog::count())->toBe(0);
});

test('reported exceptions are logged', function () {
    report(new RuntimeException('background failure'));

    expect(ActivityLog::where('action', 'exception.reported')->sole())
        ->channel->toBe('system')
        ->level->toBe(ActivityLevel::Error)
        ->message->toBe('background failure')
        ->actor_type->toBe('system')
        ->and(ActivityLog::sole()->context['exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'background failure']);
});

test('an exception thrown in a request is logged next to the failed request', function () {
    Route::middleware('web')->get('/_probe/boom', fn () => throw new LogicException('probe exploded'));

    $this->get('/_probe/boom')->assertServerError();

    expect(ActivityLog::where('action', 'exception.reported')->sole()->message)->toBe('probe exploded')
        ->and(ActivityLog::where('action', 'http.request')->sole()->status_code)->toBe(500);
});

test('exception logging can be switched off', function () {
    config(['activity-log.exceptions.enabled' => false]);

    report(new RuntimeException('quiet'));

    expect(ActivityLog::count())->toBe(0);
});
