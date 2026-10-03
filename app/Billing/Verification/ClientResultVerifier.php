<?php

namespace App\Billing\Verification;

use App\Models\PurchaseIntent;

/**
 * Phase 1 default: trusts Salla's checkout result as the owner instructed. Safeguards
 * (order id present, intent valid, order credited once) live in PurchaseService, and every
 * grant is flagged for super-admin reconciliation.
 */
class ClientResultVerifier implements PurchaseVerifier
{
    public function confirm(PurchaseIntent $intent, string $status, ?string $orderId): VerificationOutcome
    {
        return match (strtolower($status)) {
            'paid', 'success' => filled($orderId) ? VerificationOutcome::Confirmed : VerificationOutcome::Failed,
            'pending' => VerificationOutcome::Pending,
            default => VerificationOutcome::Failed,
        };
    }

    public function name(): string
    {
        return 'client_result';
    }
}
