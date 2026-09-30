<?php

namespace App\Logging;

use Illuminate\Support\Str;

/**
 * Per-request / per-job state shared by every entry written in the same unit of work.
 */
final class ActivityContext
{
    private ?string $correlationId = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    public function useCorrelationId(string $id): void
    {
        $this->correlationId = Str::limit($id, 64, '');
    }
}
