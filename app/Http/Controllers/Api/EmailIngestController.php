<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Services\Email\EmailPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $key = $secret !== '' ? $secret : 'no-secret';
        $raw = $request->getContent();
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $key);
        // Older copies of the Gmail script signed the text as Latin-1 (Apps Script's string overload
        // does that on some accounts) while sending UTF-8. Same secret, same message: accept both.
        $legacy = hash_hmac('sha256', $timestamp.'.'.mb_convert_encoding($raw, 'ISO-8859-1', 'UTF-8'), $key);
        $tolerance = (int) config('gateway.fam_email.signature_tolerance_seconds', 300);

        $fresh = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= $tolerance;

        if ($paymentAccount === null || $secret === '' || ! $fresh || ! (hash_equals($expected, $signature) || hash_equals($legacy, $signature))) {
            // Operator-only diagnostics: the reason, never the secret or signature.
            Log::error('email-ingest rejected', [
                'account' => $account,
                'reason' => $paymentAccount === null ? 'no_active_fam_email_account'
                    : ($secret === '' ? 'account_has_no_secret'
                    : (! $fresh ? 'stale_or_missing_timestamp' : 'bad_signature')),
                'has_signature' => $signature !== '',
                'body_bytes' => strlen($request->getContent()),
            ]);

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
