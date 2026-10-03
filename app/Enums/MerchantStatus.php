<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MerchantStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Active = 'active';
    case Inactive = 'inactive';
    case Uninstalled = 'uninstalled';
    case Purged = 'purged';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Syncing => 'Syncing',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Uninstalled => 'Uninstalled',
            self::Purged => 'Purged',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Syncing => 'info',
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Uninstalled => 'danger',
            self::Purged => 'gray',
        };
    }
}
