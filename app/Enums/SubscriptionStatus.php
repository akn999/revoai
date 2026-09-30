<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: string implements HasColor, HasLabel
{
    case Trial = 'trial';
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::Canceled => 'Canceled',
            self::Expired => 'Expired',
            self::Superseded => 'Superseded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Canceled => 'warning',
            self::Expired => 'danger',
            self::Superseded => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
