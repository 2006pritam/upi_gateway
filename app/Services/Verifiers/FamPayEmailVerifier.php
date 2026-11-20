<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use App\Services\Imap\SimpleImapClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatically verifies FamPay UPI payments by polling the merchant's
 * configured Gmail/IMAP inbox for official FamPay payment alert emails.
 *
 * Security checks applied to every candidate email:
 * 1. Sender whitelist (default: alerts@fampay.in)
 * 2. SPF + DKIM pass verification via the Authentication-Results header
 * 3. Order time-window check (ignores stale/old alerts)
 * 4. Incoming-credit check (rejects outgoing debit/sent notifications)
 * 5. Exact amount match in paise + 12-digit Bank UTR replay-attack check
 */
class FamPayEmailVerifier implements Verifier
{
    public const DEFAULT_HOST = 'imap.gmail.com';

    public const DEFAULT_PORT = 993;

    public const DEFAULT_SENDER = 'alerts@fampay.in';

    public function __construct(
        private readonly SimpleImapClient $imap,
    ) {}

    public function check(Order $order): VerificationResult
    {
        $account = $order->paymentAccount;
        $username = $account->credential('imap_username');
        $password = $account->credential('imap_password');

        if (! $username || ! $password) {
            return VerificationResult::pending(['error' => 'FamPay IMAP credentials not configured']);
        }

        $host = $account->credential('imap_host') ?: self::DEFAULT_HOST;
        $port = (int) ($account->credential('imap_port') ?: self::DEFAULT_PORT);
        $sender = strtolower(trim($account->credential('imap_sender') ?: self::DEFAULT_SENDER));
        $sinceDate = ($order->created_at ?? now())->copy()->subDay()->format('d-M-Y');

        try {
            $messages = $this->imap->fetchRecentFromSender(
                host: $host,
                port: $port,
                username: $username,
                password: $password,
                sender: $sender,
                sinceDate: $sinceDate,
            );
        } catch (Throwable $e) {
            Log::warning('FamPay IMAP check failed', [
                'order_id' => $order->order_no,
                'error' => $e->getMessage(),
                // Never log IMAP username, password, or email contents.
            ]);

            return VerificationResult::pending(['error' => 'IMAP check failed']);
        }

        $expectedPaise = $this->toPaise($order->amount);

        foreach ($messages as $message) {
            $parsed = $this->parseMessage($message['headers'], $message['body'], $sender);
            if ($parsed === null) {
                continue;
            }

            if (! $this->isWithinOrderWindow($order, $parsed['date'])) {
                continue;
            }

            if ($parsed['amount_paise'] !== $expectedPaise) {
                continue;
            }

            $utr = $parsed['utr'];
            if ($utr !== null && $this->isUtrAlreadyUsed($order, $utr)) {
                continue;
            }

            // Prefer explicit order-number or customer-submitted UTR match.
            $matchesOrderNo = $order->order_no !== '' && stripos($parsed['text'], $order->order_no) !== false;
            $matchesCustomerUtr = $utr !== null
                && $order->customer_utr !== null
                && hash_equals(strtoupper($order->customer_utr), strtoupper($utr));

            if (! $matchesOrderNo && ! $matchesCustomerUtr && ! $this->isOldestPendingForAmount($order)) {
                continue;
            }

            return VerificationResult::paid(
                utr: $utr,
                txnId: $parsed['message_id'],
                raw: [
                    'source' => 'fampay_email',
                    'sender' => $parsed['from'],
                    'subject' => $parsed['subject'],
                    'email_date' => $parsed['date']?->toIso8601String(),
                    'amount_paise' => $parsed['amount_paise'],
                    'matched_by' => $matchesOrderNo ? 'order_no' : ($matchesCustomerUtr ? 'customer_utr' : 'amount_window'),
                ],
            );
        }

        return VerificationResult::pending();
    }

    /**
     * Validate headers (sender + SPF/DKIM) and extract credit details from an email.
     *
     * @return array{from: string, subject: string, message_id: ?string, date: ?Carbon, amount_paise: int, utr: ?string, text: string}|null
     */
    public function parseMessage(string $rawHeaders, string $rawBody, string $allowedSender): ?array
    {
        $headers = $this->parseHeaders($rawHeaders);

        $fromEmail = $this->extractEmailAddress($headers['from'] ?? '');
        if ($fromEmail === null || ! $this->senderMatches($fromEmail, $allowedSender)) {
            return null;
        }

        if (! $this->passesEmailAuth($headers['authentication-results'] ?? '')) {
            return null;
        }

        $subject = $this->decodeHeader($headers['subject'] ?? '');
        $bodyText = $this->normalizeBody($rawBody);
        $combined = trim($subject."\n".$bodyText);

        if (! $this->isIncomingCredit($combined)) {
            return null;
        }

        $amountPaise = $this->extractAmountPaise($combined);
        if ($amountPaise === null) {
            return null;
        }

        return [
            'from' => $fromEmail,
            'subject' => $subject,
            'message_id' => $this->extractMessageId($headers['message-id'] ?? ''),
            'date' => $this->parseDate($headers['date'] ?? ''),
            'amount_paise' => $amountPaise,
            'utr' => $this->extractUtr($combined),
            'text' => $combined,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        // Unfold RFC 5322 multi-line headers.
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders) ?? $rawHeaders;
        $lines = preg_split('/\r?\n/', $unfolded) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $val = trim($value);
            $parsed[$key] = isset($parsed[$key]) ? $parsed[$key].'; '.$val : $val;
        }

        return $parsed;
    }

    private function extractEmailAddress(string $header): ?string
    {
        if (preg_match('/<([^>\s]+@[^>\s]+)>/', $header, $m)) {
            return strtolower(trim($m[1]));
        }

        if (preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $header, $m)) {
            return strtolower(trim($m[1]));
        }

        return null;
    }

    private function senderMatches(string $fromEmail, string $allowedSender): bool
    {
        if ($fromEmail === $allowedSender) {
            return true;
        }

        // Allow domain-level match if merchant configured "@fampay.in"
        if (str_starts_with($allowedSender, '@')) {
            return str_ends_with($fromEmail, $allowedSender);
        }

        return false;
    }

    /**
     * Require both DKIM and SPF pass in the mail server's Authentication-Results
     * header to drop spoofed SMTP relay emails.
     */
    private function passesEmailAuth(string $authResults): bool
    {
        if ($authResults === '') {
            return false;
        }

        $hasDkimPass = (bool) preg_match('/\bdkim=pass\b/i', $authResults);
        $hasSpfPass = (bool) preg_match('/\bspf=pass\b/i', $authResults);

        return $hasDkimPass && $hasSpfPass;
    }

    private function isIncomingCredit(string $text): bool
    {
        $hasCredit = (bool) preg_match(
            '/\b(?:received|credited|paid\s+you|sent\s+you|added\s+to\s+your|money\s+received|payment\s+received)\b/i',
            $text,
        );

        if (! $hasCredit) {
            return false;
        }

        // Reject outgoing transfers if no explicit incoming phrase is present.
        $looksOutgoing = (bool) preg_match(
            '/\b(?:you\s+paid|you\s+sent|debited\s+from|transferred\s+to)\b/i',
            $text,
        );

        return ! $looksOutgoing;
    }

    private function extractAmountPaise(string $text): ?int
    {
        if (preg_match('/(?:₹|Rs\.?|INR)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $text, $m)) {
            $normalized = str_replace(',', '', $m[1]);

            return $this->toPaise($normalized);
        }

        return null;
    }

    private function extractUtr(string $text): ?string
    {
        // Prefer explicitly labeled 12-digit UPI UTR / Reference numbers.
        if (preg_match(
            '/\b(?:UTR|UPI\s*Ref(?:erence)?(?:\s*(?:No|Number|ID))?|Ref(?:erence)?\s*(?:No|Number|ID)|Bank\s*Ref(?:erence)?|Transaction\s*ID|Txn\s*ID)\s*[:#\-]?\s*([0-9]{12})\b/i',
            $text,
            $m,
        )) {
            return $m[1];
        }

        // Fallback to any standalone 12-digit UPI reference number.
        if (preg_match('/\b([0-9]{12})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractMessageId(string $header): ?string
    {
        $trimmed = trim($header, " \t\r\n<>");

        return $trimmed !== '' ? mb_substr($trimmed, 0, 120) : null;
    }

    private function decodeHeader(string $value): string
    {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    private function normalizeBody(string $rawBody): string
    {
        $decoded = quoted_printable_decode($rawBody);

        // Decode base64 MIME part if present.
        if (preg_match('/Content-Transfer-Encoding:\s*base64\r?\n\r?\n([A-Za-z0-9+\/\r\n=]+)/i', $rawBody, $m)) {
            $b64 = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
            if (is_string($b64) && $b64 !== '') {
                $decoded .= "\n".$b64;
            }
        }

        $plain = html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/\s+/', ' ', $plain) ?? $plain;
    }

    private function parseDate(string $dateHeader): ?Carbon
    {
        if ($dateHeader === '') {
            return null;
        }

        try {
            return Carbon::parse($dateHeader);
        } catch (Throwable) {
            return null;
        }
    }

    private function isWithinOrderWindow(Order $order, ?Carbon $emailDate): bool
    {
        if ($emailDate === null) {
            return false;
        }

        $start = ($order->created_at ?? now())->copy()->subMinutes(2);
        $end = ($order->expires_at ?? now()->addMinutes(15))->copy()->addMinutes(10);

        return $emailDate->betweenIncluded($start, $end);
    }

    private function isUtrAlreadyUsed(Order $order, string $utr): bool
    {
        return Order::where('user_id', $order->user_id)
            ->whereKeyNot($order->id)
            ->where('utr', $utr)
            ->exists();
    }

    /**
     * When matching strictly by amount (without an order_no or customer UTR),
     * only settle the oldest pending order for that exact amount on this
     * account so concurrent same-amount orders never double-claim one email.
     */
    private function isOldestPendingForAmount(Order $order): bool
    {
        $oldestId = Order::where('payment_account_id', $order->payment_account_id)
            ->where('status', Order::PENDING)
            ->where('amount', $order->amount)
            ->orderBy('id')
            ->value('id');

        return $oldestId === null || (int) $oldestId === (int) $order->id;
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
