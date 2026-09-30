<?php

namespace App\Salla;

use App\Salla\Handlers\AppEventHandler;
use Illuminate\Contracts\Container\Container;

class AppEventRouter
{
    public function __construct(private Container $app) {}

    public function for(string $event): ?AppEventHandler
    {
        $class = config('salla.handlers', [])[$event] ?? null;

        return $class ? $this->app->make($class) : null;
    }
}
