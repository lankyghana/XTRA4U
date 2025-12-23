<?php

namespace App\Contracts\Gateways;

interface HandlesGenericPayments
{
    /**
     * Initiate a generic payment (non-Order flows like AFA registration).
     */
    public function initiatePayment(
        string $email,
        float $amount,
        string $callbackUrl,
        ?string $reference = null,
        array $metadata = []
    ): array;
}
