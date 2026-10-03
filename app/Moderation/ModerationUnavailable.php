<?php

namespace App\Moderation;

use RuntimeException;

/**
 * Moderation could not run, so the request fails closed: nothing is shown and credits are released (FR-MOD-005).
 */
class ModerationUnavailable extends RuntimeException
{
    public function __construct(string $message = 'Content could not be checked right now, try again')
    {
        parent::__construct($message);
    }
}
