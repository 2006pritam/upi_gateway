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
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret !== '' ? $secret : 'no-secret');
        $tolerance = (int) config('gateway.fam_email.signature_tolerance_seconds', 300);

        $fresh = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= $tolerance;

        if ($paymentAccount === null || $secret === '' || ! $fresh || ! hash_equals($expected, $signature)) {
            // Operator-only diagnostics: the reason, never the secret or signature.
            Log::error('email-ingest rejected', [
                'account' => $account,
                'reason' => $paymentAccount === null ? 'no_active_fam_email_account'
                    : ($secret === '' ? 'account_has_no_secret'
                    : (! $fresh ? 'stale_or_missing_timestamp' : 'bad_signature')),
                'has_signature' => $signature !== '',
                'sig_len' => strlen($signature),
                'secret_len' => strlen($secret),
                'diag' => $paymentAccount === null || $secret === '' ? [] : $this->diagnose($request->getContent(), $timestamp, $signature, $secret),
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

    /** Which alternative body encodings WOULD have matched? (diagnostic booleans only; never used to authenticate) */
    private function diagnose(string $raw, string $ts, string $sig, string $secret): array
    {
        $decoded = json_decode($raw, true);
        $variants = [
            'raw' => $raw,
            'ascii_escaped' => is_array($decoded) ? json_encode($decoded) : '',
            'unescaped_unicode' => is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'latin1' => mb_convert_encoding($raw, 'ISO-8859-1', 'UTF-8'),
            'crlf' => str_replace("\n", "\r\n", $raw),
        ];
        $out = [];
        foreach ($variants as $name => $body) {
            $out[$name] = $body !== '' && hash_equals(hash_hmac('sha256', $ts.'.'.$body, $secret), $sig);
        }
        $out['trimmed_secret'] = hash_equals(hash_hmac('sha256', $ts.'.'.$raw, trim($secret)), $sig);
        $out['ts_only_body'] = hash_equals(hash_hmac('sha256', $raw, $secret), $sig);

        return $out;
    }
}
