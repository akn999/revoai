<?php

namespace Tests\Fixtures;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Salla\Handlers\AppEventHandler;

class RecordingHandler implements AppEventHandler
{
    /** @var array<int, string> */
    public static array $handled = [];

    public function handle(AppEvent $event): HandlerResult
    {
        self::$handled[] = $event->event;

        return HandlerResult::Processed;
    }
}
