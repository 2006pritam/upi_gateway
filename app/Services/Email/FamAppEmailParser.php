<?php

namespace App\Services\Email;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads FamApp "You received ₹X in your FamX account" e-mails.
 *
 * Only incoming-money mails are accepted: the subject and the body must both
 * say money was received and must agree on the amount. Mails about money
 * sent ("Your payment of ₹X is successful") and anything else return null.
 * A UTR is required, because it is what makes a payment unique.
 */
class FamAppEmailParser
{
    private const MONEY = '(?:₹|Rs\.?)\s?([\d,]+(?:\.\d{1,2})?)';

    public function parse(string $subject, string $body): ?ParsedEmailPayment
    {
        $subject = $this->squish($subject);
        $text = $this->squish($body);

        if (! preg_match('/^You received '.self::MONEY.' in your FamX account$/iu', $subject, $fromSubject)) {
            return null;
        }

        if (! preg_match('/successfully received '.self::MONEY.'/iu', $text, $fromBody)) {
            return null;
        }

        $amount = $this->money($fromSubject[1]);

        if ($amount === null || $amount !== $this->money($fromBody[1])) {
            return null;
        }

        if (! preg_match('/\bUTR\s*:?\s*([A-Za-z0-9]{6,30})\b/i', $text, $utr)) {
            return null;
        }

        $txnId = preg_match('/transaction id\s*:?\s*([A-Za-z0-9]{6,40})/i', $text, $m) ? strtoupper($m[1]) : null;

        $payer = preg_match('/successfully received '.self::MONEY.' from (.+?)(?: at \d{1,2}:\d{2}| Transaction ID)/iu', $text, $m)
            ? mb_substr(trim($m[2]), 0, 120)
            : null;

        $purpose = null;
        if (preg_match('/Purpose\s*:\s*(.*?)\s*(?:\.\s*If this was not done|$)/iu', $text, $m)) {
            $purpose = trim(rtrim(trim($m[1]), '.'));
            $purpose = $purpose === '' ? null : mb_substr($purpose, 0, 255);
        }

        return new ParsedEmailPayment(
            amount: $amount,
            utr: strtoupper($utr[1]),
            txnId: $txnId,
            payer: $payer,
            purpose: $purpose,
            paidAt: $this->paidAt($text),
        );
    }

    private function money(string $value): ?string
    {
        $value = str_replace(',', '', $value);

        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /** "07:53 PM IST, 24 September 2026" */
    private function paidAt(string $text): ?CarbonImmutable
    {
        if (! preg_match('/(\d{1,2}:\d{2})\s?([AP]M)\s+IST,?\s+(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/i', $text, $m)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'g:i A j F Y',
                "{$m[1]} ".strtoupper($m[2])." {$m[3]} {$m[4]} {$m[5]}",
                'Asia/Kolkata',
            ) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
