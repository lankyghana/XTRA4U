<?php

namespace App\Services\UtilityBills\Data;

/**
 * Result of POST /utilities/pay (first call or idempotent replay).
 * `providerReference` is the provider's own UTIL-... reference, the only one
 * valid for status queries — never the idempotency key we sent.
 */
final class PayResult
{
    public function __construct(
        public readonly string $providerReference,
        public readonly ?string $providerOrderId,
        public readonly ?string $status,
        public readonly bool $alreadyProcessed,
        public readonly ?string $commissionSharePercent,
    ) {}
}
