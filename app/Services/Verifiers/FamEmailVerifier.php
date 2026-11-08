<?php

namespace App\Services\Verifiers;

use App\Models\Order;

/**
 * FamApp has no status API. Payments are confirmed the other way round: the
 * merchant's mailbox forwards each "money received" e-mail to the gateway
 * (see EmailPaymentService), which marks the matching order paid. Polling
 * the provider therefore never has anything to report.
 */
class FamEmailVerifier implements Verifier
{
    public function check(Order $order): VerificationResult
    {
        return VerificationResult::pending();
    }
}
