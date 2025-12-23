<?php

namespace App\Services;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\VendorWithdrawal;

/**
 * @deprecated Use GatewayManager::payout() (and the concrete payout services under App\Services\Payouts).
 *
 * This class previously contained gateway-routing logic. It is now a thin adapter to preserve backwards
 * compatibility if anything external calls it.
 */
class MomoPayoutService implements HandlesPayouts
{
    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function processPayout(VendorWithdrawal $withdrawal): array
    {
        return app(GatewayManager::class)->payout($withdrawal);
    }
}
