<?php

namespace App\Services\Email;

use Carbon\CarbonImmutable;

/** A "money received" e-mail reduced to the fields the gateway needs. */
final class ParsedEmailPayment
{
    public function __construct(
        public readonly string $amount,
        public readonly string $utr,
        public readonly ?string $txnId,
        public readonly ?string $payer,
        public readonly ?string $purpose,
        public readonly ?CarbonImmutable $paidAt,
    ) {}
}
