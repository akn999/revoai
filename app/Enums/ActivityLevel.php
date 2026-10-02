<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Monolog\Level;

enum ActivityLevel: string implements HasColor, HasIcon, HasLabel
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    public function getLabel(): string
    {
        return match ($this) {
            self::Debug => 'Debug',
            self::Info => 'Info',
            self::Notice => 'Notice',
            self::Warning => 'Warning',
            self::Error => 'Error',
            self::Critical => 'Critical',
            self::Alert => 'Alert',
            self::Emergency => 'Emergency',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Debug => 'gray',
            self::Info => 'info',
            self::Notice => 'primary',
            self::Warning => 'warning',
            self::Error, self::Critical, self::Alert, self::Emergency => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Debug => Heroicon::OutlinedBugAnt,
            self::Info => Heroicon::OutlinedInformationCircle,
            self::Notice => Heroicon::OutlinedBell,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
            self::Error => Heroicon::OutlinedXCircle,
            self::Critical => Heroicon::OutlinedFire,
            self::Alert => Heroicon::OutlinedBellAlert,
            self::Emergency => Heroicon::OutlinedShieldExclamation,
        };
    }

    /**
     * Levels shown on the "Problems" tab.
     *
     * @return array<int, self>
     */
    public static function problems(): array
    {
        return [self::Warning, self::Error, self::Critical, self::Alert, self::Emergency];
    }

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
