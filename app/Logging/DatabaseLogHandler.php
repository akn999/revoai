<?php

namespace App\Logging;

use App\Enums\ActivityLevel;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler so Log::channel('database') (or a stack containing it) lands in the activity log.
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    public function __construct(Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        app(ActivityLogger::class)->write([
            'level' => ActivityLevel::fromMonolog($record->level),
            'channel' => 'log',
            'action' => 'log.'.strtolower($record->level->getName()),
            'message' => $record->message,
            'context' => [...$record->context, 'log_channel' => $record->channel, 'extra' => $record->extra],
        ]);
    }
}
