<?php

namespace App\Products\Exceptions;

use RuntimeException;

class PlanLocked extends RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct('This feature is not included in your current plan.');
    }
}
