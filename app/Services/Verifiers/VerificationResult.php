<?php

namespace App\Services\Verifiers;

final class VerificationResult
{
    private function __construct(
        public readonly bool $paid,
        public readonly ?string $utr = null,
        public readonly ?string $txnId = null,
        public readonly array $raw = [],
    ) {}

    public static function paid(?string $utr, ?string $txnId, array $raw = []): self
    {
        return new self(true, $utr, $txnId, $raw);
    }

    public static function pending(array $raw = []): self
    {
        return new self(false, raw: $raw);
    }
}
