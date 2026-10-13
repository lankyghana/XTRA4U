<?php

namespace App\Contracts\Gateways;

interface ResolvesPayoutAccountName
{
    /**
     * Resolve the registered account name for a mobile money number.
     *
     * @param  string|null  $network  VendorWithdrawal::NETWORK_* value (required by some gateways)
     * @return array{success: bool, name: ?string, message: ?string}
     */
    public function lookupAccountName(string $phone, ?string $network = null): array;
}
