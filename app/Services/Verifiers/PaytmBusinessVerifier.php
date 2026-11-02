<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a Paytm for Business merchant's transaction by our order number
 * (passed as `tr` in the UPI link). Uses Paytm's unofficial status endpoint,
 * so any error is treated as "still pending" rather than a failure.
 */
class PaytmBusinessVerifier implements Verifier
{
    public function check(Order $order): VerificationResult
    {
        $mid = $order->paymentAccount->credential('mid');

        if (! $mid) {
            return VerificationResult::pending(['error' => 'Paytm MID not configured']);
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get(config('gateway.paytm_status_url'), [
                'JsonData' => json_encode(['MID' => $mid, 'ORDERID' => $order->order_no]),
            ]);
            $data = $response->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('Paytm status check failed', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            return VerificationResult::pending(['error' => $e->getMessage()]);
        }

        if (($data['STATUS'] ?? null) !== 'TXN_SUCCESS') {
            return VerificationResult::pending($data);
        }

        // Never trust status alone: the amount paid must match the order exactly.
        if ($this->toPaise($data['TXNAMOUNT'] ?? 0) !== $this->toPaise($order->amount)) {
            Log::warning('Paytm amount mismatch', ['order' => $order->order_no, 'response' => $data]);

            return VerificationResult::pending($data + ['error' => 'Amount mismatch']);
        }

        return VerificationResult::paid(
            utr: $data['BANKTXNID'] ?? null,
            txnId: $data['TXNID'] ?? null,
            raw: $data,
        );
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
