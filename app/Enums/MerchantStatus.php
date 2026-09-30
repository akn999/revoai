<?php

namespace App\Enums;

enum MerchantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Uninstalled = 'uninstalled';
}
