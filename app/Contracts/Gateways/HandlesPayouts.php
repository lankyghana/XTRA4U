<?php

namespace App\Contracts\Gateways;

use App\Models\VendorWithdrawal;

interface HandlesPayouts
{
    /**
     * Process a vendor withdrawal payout.
     */
    public function processPayout(VendorWithdrawal $withdrawal): array;

    /**
     * Whether the gateway is properly configured.
     */
    public function isConfigured(): bool;
}
