<?php

namespace App\Billing\Verification;

use App\Models\PurchaseIntent;

/**
 * Placeholder (open item OI-01): Salla has not documented a server-side purchase
 * confirmation yet, so nothing can be confirmed this way and every result stays pending.
 */
class SallaConfirmedVerifier implements PurchaseVerifier
{
    public function confirm(PurchaseIntent $intent, string $status, ?string $orderId): VerificationOutcome
    {
        return VerificationOutcome::Pending;
    }

    public function name(): string
    {
        return 'salla_confirmed';
    }
}
