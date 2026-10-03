<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * A provider failed. The message is safe to show merchants: no internal detail (FR-AI-006).
 */
class AiUnavailable extends RuntimeException
{
    public static function busy(?\Throwable $previous = null): self
    {
        return new self('The AI service is busy, try again', 0, $previous);
    }
}
