<?php

namespace App\Ai\Dto;

final class ImageJobStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public function __construct(public readonly string $state, public readonly ?string $error = null) {}

    public function isFinished(): bool
    {
        return in_array($this->state, [self::COMPLETED, self::FAILED], true);
    }
}
