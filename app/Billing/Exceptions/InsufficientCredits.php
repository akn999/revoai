<?php

namespace App\Billing\Exceptions;

use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $available)
    {
        parent::__construct("Insufficient credits: {$required} required, {$available} available.");
    }
}
