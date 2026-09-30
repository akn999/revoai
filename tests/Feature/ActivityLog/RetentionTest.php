<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Artisan;

test('the default retention is fourteen days', function () {
    expect(config('activity-log.retention_days'))->toBe(14);
});

test('pruning removes only rows older than the retention', function () {
    ActivityLog::factory()->create(['action' => 'fresh']);
    ActivityLog::factory()->create(['action' => 'thirteen.days', 'created_at' => now()->subDays(13)]);
    ActivityLog::factory()->olderThanDays(14)->create(['action' => 'stale']);
    ActivityLog::factory()->olderThanDays(90)->create(['action' => 'ancient']);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    expect(ActivityLog::orderBy('id')->pluck('action')->all())->toBe(['fresh', 'thirteen.days']);
});

test('the retention window follows the configured number of days', function () {
    config(['activity-log.retention_days' => 3]);
    ActivityLog::factory()->create(['action' => 'two.days', 'created_at' => now()->subDays(2)]);
    ActivityLog::factory()->olderThanDays(3)->create(['action' => 'four.days']);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    expect(ActivityLog::pluck('action')->all())->toBe(['two.days']);
});

test('zero days keeps everything', function () {
    config(['activity-log.retention_days' => 0]);
    ActivityLog::factory()->olderThanDays(1000)->create();

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    expect(ActivityLog::count())->toBe(1);
});

test('pruning is scheduled daily', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('model:prune')->toContain('ActivityLog');
});

test('the retention value is read from the environment', function () {
    putenv('ACTIVITY_LOG_RETENTION_DAYS=30');
    $_ENV['ACTIVITY_LOG_RETENTION_DAYS'] = $_SERVER['ACTIVITY_LOG_RETENTION_DAYS'] = '30';

    try {
        $config = require config_path('activity-log.php');
        expect($config['retention_days'])->toBe(30);
    } finally {
        putenv('ACTIVITY_LOG_RETENTION_DAYS');
        unset($_ENV['ACTIVITY_LOG_RETENTION_DAYS'], $_SERVER['ACTIVITY_LOG_RETENTION_DAYS']);
    }
});
