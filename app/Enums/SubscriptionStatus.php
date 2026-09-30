<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
