<?php

namespace App\Services\Ussd;

use App\Models\UssdSubscription;
use RuntimeException;

/**
 * Mints the dialled code a vendor's customers use: <base><extension>*<vendor_id>#
 *
 * Uniqueness is structural rather than probabilistic. vendors.id is unique, so
 * two vendors on the same plan can never produce the same code, and a vendor
 * switching plans changes the extension segment. The unique index on
 * ussd_subscriptions.ussd_code is the backstop, and only *live* subscriptions
 * hold a code — UssdSubscriptionService::releaseSlot() clears it when one
 * expires or is superseded, so a vendor re-purchasing the same plan can be
 * re-issued their previous code.
 */
class UssdCodeGenerator
{
    public function __construct(private readonly UssdConfig $config) {}

    public function generateFor(int $vendorId, string $extensionCode): string
    {
        $baseCode = $this->config->baseCode();

        if (trim($baseCode) === '') {
            throw new RuntimeException('No USSD base code is configured. Set one in Admin → USSD Settings.');
        }

        $code = UssdSubscription::buildUssdCode($baseCode, $extensionCode, $vendorId);

        $this->assertAvailable($code, $vendorId);

        return $code;
    }

    /**
     * The unique index is the real guarantee; this exists so a collision surfaces
     * as an explanatory exception rather than a raw QueryException at insert time.
     */
    private function assertAvailable(string $code, int $vendorId): void
    {
        $conflict = UssdSubscription::query()
            ->where('ussd_code', $code)
            ->where('vendor_id', '!=', $vendorId)
            ->exists();

        if ($conflict) {
            throw new RuntimeException("USSD code {$code} is already assigned to another vendor.");
        }
    }
}
