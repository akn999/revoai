<?php

namespace Tests\Fixtures;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Salla\Handlers\AppEventHandler;
use RuntimeException;

class ThrowingHandler implements AppEventHandler
{
    public function handle(AppEvent $event): HandlerResult
    {
        throw new RuntimeException('handler exploded');
    }
}
