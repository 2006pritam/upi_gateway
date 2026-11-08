<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Services\Email\EmailPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives forwarded FamApp e-mails from the merchant's own mailbox script.
 *
 * Auth is a per-account secret: X-Signature = HMAC-SHA256(secret, "<X-Timestamp>.<raw body>"),
 * valid for a few minutes. Every failure looks the same (401) so account IDs cannot be probed.
 */
class EmailIngestController extends Controller
{
    public function __invoke(Request $request, int $account, EmailPaymentService $emails): JsonResponse
    {
        $paymentAccount = PaymentAccount::where('provider', 'fam_email')->where('is_active', true)->find($account);
        $secret = (string) $paymentAccount?->credential('ingest_secret');

        $timestamp = (string) $request->header('X-Timestamp');
        $signature = strtolower((string) $request->header('X-Signature'));
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret !== '' ? $secret : 'no-secret');
        $tolerance = (int) config('gateway.fam_email.signature_tolerance_seconds', 300);

        $fresh = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= $tolerance;

        if ($paymentAccount === null || $secret === '' || ! $fresh || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'from' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:20000'],
            'received_at' => ['required', 'date'],
            'auth_results' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json($emails->ingest($paymentAccount, $data));
    }
}
