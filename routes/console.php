<?php

use App\Models\ActivityLog;
use App\Models\AppEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('salla:tokens:refresh')->hourly()->withoutOverlapping();
Schedule::command('salla:subscriptions:sweep')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [AppEvent::class, ActivityLog::class]])->daily();

Schedule::command('revo:stores:purge')->daily()->withoutOverlapping();
Schedule::command('revo:billing:sweep')->hourly()->withoutOverlapping();
Schedule::command('revo:wallets:verify')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('revo:retention:run')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('revo:media:expiry-digest')->dailyAt('09:00')->withoutOverlapping();
