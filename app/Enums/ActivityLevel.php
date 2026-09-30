<?php

namespace App\Enums;

use Monolog\Level;

enum ActivityLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    public static function fromMonolog(Level $level): self
    {
        return self::from(strtolower($level->getName()));
    }

    public static function fromStatusCode(int $status): self
    {
        return match (true) {
            $status >= 500 => self::Error,
            $status >= 400 => self::Warning,
            default => self::Info,
        };
    }

    public static function coerce(self|string|null $level): self
    {
        return $level instanceof self ? $level : (self::tryFrom(strtolower((string) $level)) ?? self::Info);
    }
}
