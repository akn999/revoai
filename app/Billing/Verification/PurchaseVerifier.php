<?php

namespace App\Billing\Verification;

use App\Models\PurchaseIntent;

interface PurchaseVerifier
{
    /**
     * Decide whether a checkout result means the pack was really paid for.
     */
    public function confirm(PurchaseIntent $intent, string $status, ?string $orderId): VerificationOutcome;

    /**
     * Strategy name stored on the intent.
     */
    public function name(): string;
}
