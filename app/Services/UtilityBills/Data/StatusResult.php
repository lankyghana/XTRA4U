<?php

namespace App\Services\UtilityBills\Data;

/** Result of GET /utilities/orders/{reference}. */
final class StatusResult
{
    public function __construct(
        public readonly string $providerReference,
        /** Raw provider status, normalised to lowercase, or null when unrecognised. */
        public readonly ?string $status,
        public readonly ?string $paymentStatus,
        public readonly ?string $reason,
        /** Provider -> XTRA4U commission. null until the provider order completes. */
        public readonly ?string $commissionEarned,
        public readonly ?string $amount,
        public readonly ?string $accountNumber,
    ) {}
}
