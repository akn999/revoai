<?php

namespace App\Logging;

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingActivity channel(string $channel)
 * @method static ActivityLog|null log(ActivityLevel|string $level, string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null debug(string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null info(string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null notice(string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null warning(string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null error(string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null critical(string $channel, string $action, ?string $message = null, array<string, mixed> $context = [])
 * @method static ActivityLog|null write(array<string, mixed> $attributes)
 *
 * @see ActivityLogger
 */
class Activity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ActivityLogger::class;
    }
}
