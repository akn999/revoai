<?php

use App\Enums\ActivityLevel;
use App\Logging\DatabaseLogHandler;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

test('the database log channel stores Laravel log records', function () {
    Log::channel('database')->warning('Disk almost full', ['disk' => '/dev/sda1', 'password' => 'nope']);

    expect(ActivityLog::sole())
        ->channel->toBe('log')
        ->action->toBe('log.warning')
        ->level->toBe(ActivityLevel::Warning)
        ->message->toBe('Disk almost full')
        ->context->toMatchArray(['disk' => '/dev/sda1', 'password' => '[redacted]']);
});

test('a stack containing the database channel logs through Log facade calls', function () {
    config(['logging.channels.combined' => ['driver' => 'stack', 'channels' => ['database']]]);

    Log::channel('combined')->error('Something broke');

    expect(ActivityLog::sole())->action->toBe('log.error')->level->toBe(ActivityLevel::Error);
});

test('the channel level threshold is respected', function () {
    $logger = Log::build(['driver' => 'monolog', 'handler' => DatabaseLogHandler::class, 'level' => 'error']);

    $logger->info('too quiet');
    $logger->error('loud enough');

    expect(ActivityLog::pluck('message')->all())->toBe(['loud enough']);
});

test('a database log failure does not recurse even if the fallback is the database channel', function () {
    config(['activity-log.table' => 'missing_table', 'activity-log.fallback_channel' => 'database']);

    Log::channel('database')->error('cannot store this');

    expect(true)->toBeTrue();
});
