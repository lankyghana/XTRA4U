<?php

namespace App\Services\Ussd;

use App\Models\UssdSubscription;
use App\Models\Vendor;

/**
 * The outcome of resolving a dialled code to a vendor.
 *
 * `prefixLength` is how many leading segments of the gateway's `text` field
 * belonged to the dialled code rather than to menu input.
 */
class UssdRouting
{
    public const REASON_OK = 'ok';

    public const REASON_NO_VENDOR = 'no_vendor';

    public const REASON_NO_SUBSCRIPTION = 'no_subscription';

    public const REASON_STALE_CODE = 'stale_code';

    public const REASON_EXPIRED = 'expired';

    public const REASON_EXHAUSTED = 'exhausted';

    public function __construct(
        public readonly ?Vendor $vendor,
        public readonly ?UssdSubscription $subscription,
        public readonly int $prefixLength,
        public readonly string $reason,
    ) {}

    public static function fail(string $reason, int $prefixLength = 0): self
    {
        return new self(null, null, $prefixLength, $reason);
    }

    public function isServiceable(): bool
    {
        return $this->reason === self::REASON_OK;
    }
}
