<?php

namespace App\Billing\Verification;

enum VerificationOutcome: string
{
    case Confirmed = 'confirmed';
    case Pending = 'pending';
    case Failed = 'failed';
}
