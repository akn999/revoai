<?php

namespace App\Console\Commands;

use App\Enums\AppEventStatus;
use App\Jobs\ProcessAppEvent;
use App\Models\AppEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('salla:events:replay {id? : The app_events id to replay} {--failed : Replay every failed event}')]
#[Description('Reset stored Salla events to received and process them again')]
class ReplayAppEvent extends Command
{
    public function handle(): int
    {
        if (! $this->argument('id') && ! $this->option('failed')) {
            $this->error('Pass an event id or --failed.');

            return self::FAILURE;
        }

        $events = $this->argument('id')
            ? AppEvent::whereKey($this->argument('id'))->get()
            : AppEvent::where('status', AppEventStatus::Failed)->orderBy('event_created_at')->orderBy('id')->get();

        if ($events->isEmpty()) {
            $this->warn('No matching events.');

            return self::FAILURE;
        }

        foreach ($events as $event) {
            $event->update(['status' => AppEventStatus::Received, 'attempts' => 0, 'error' => null, 'processed_at' => null]);

            ProcessAppEvent::dispatch($event->id, $event->merchant_id)->onQueue(config('salla.queue'));
        }

        $this->info("Replayed {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
