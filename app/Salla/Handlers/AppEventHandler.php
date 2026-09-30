<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Salla\HandlerResult;

interface AppEventHandler
{
    public function handle(AppEvent $event): HandlerResult;
}
