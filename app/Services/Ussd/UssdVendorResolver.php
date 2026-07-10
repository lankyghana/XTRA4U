<?php

namespace App\Services\Ussd;

use App\Models\UssdSubscription;
use App\Models\Vendor;

/**
 * Resolves the vendor a customer dialled, and whether that vendor may be served.
 *
 * Three routing forms, tried in order:
 *   1. <extension>*<vendor id>   e.g. "45*102"  — the subscription code format
 *   2. <vendor_code>             e.g. "XTRA4U-001" — legacy, pre-subscription
 *   3. nothing                   — the admin-configured default vendor
 *
 * Every form then passes the same subscription gate. There is no longer a
 * fallback to "the first approved vendor" or to a hardcoded vendor id: routing
 * a customer's order — and its commission — to an arbitrary vendor is worse
 * than refusing the call.
 */
class UssdVendorResolver
{
    public function __construct(
        private readonly UssdRequestParser $parser,
        private readonly UssdConfig $config,
    ) {}

    public function resolve(string $text): UssdRouting
    {
        [$vendor, $prefixLength, $dialledExtension] = $this->locateVendor($text);

        if (! $vendor) {
            return UssdRouting::fail(UssdRouting::REASON_NO_VENDOR, $prefixLength);
        }

        $subscription = $vendor->currentUssdSubscription()->first();

        if (! $subscription) {
            return new UssdRouting($vendor, null, $prefixLength, UssdRouting::REASON_NO_SUBSCRIPTION);
        }

        // The vendor switched plans, so this code's extension no longer matches
        // the one they hold. Refuse rather than silently serving a stale code.
        if ($dialledExtension !== null && $subscription->extension_code !== $dialledExtension) {
            return new UssdRouting($vendor, $subscription, $prefixLength, UssdRouting::REASON_STALE_CODE);
        }

        if ($subscription->status !== UssdSubscription::STATUS_ACTIVE || $subscription->isExpired()) {
            return new UssdRouting($vendor, $subscription, $prefixLength, UssdRouting::REASON_EXPIRED);
        }

        if (! $subscription->hasRemainingSessions()) {
            return new UssdRouting($vendor, $subscription, $prefixLength, UssdRouting::REASON_EXHAUSTED);
        }

        return new UssdRouting($vendor, $subscription, $prefixLength, UssdRouting::REASON_OK);
    }

    /**
     * @return array{0: ?Vendor, 1: int, 2: ?string} vendor, prefix length, dialled extension
     */
    private function locateVendor(string $text): array
    {
        $segments = $this->parser->segments($text);
        $first = $segments[0] ?? null;
        $second = $segments[1] ?? null;

        // Form 1: <extension>*<vendor id>. An unknown id fails here rather than
        // falling through to the default vendor — the customer named a vendor,
        // and quietly serving a different one would misattribute their order.
        if ($this->parser->isNumericSegment($first) && $this->parser->isNumericSegment($second)) {
            return [$this->approvedVendor((int) $second), 2, $first];
        }

        // Form 2: legacy vendor_code, which is never all-digits.
        if (is_string($first) && $first !== '' && ! $this->parser->isNumericSegment($first)) {
            $vendor = Vendor::where('vendor_code', $first)->where('is_approved', true)->first();

            return [$vendor, 1, null];
        }

        // Form 3: the base code alone.
        $defaultId = $this->config->defaultVendorId();

        return [$defaultId ? $this->approvedVendor($defaultId) : null, 0, null];
    }

    private function approvedVendor(int $id): ?Vendor
    {
        return Vendor::whereKey($id)->where('is_approved', true)->first();
    }
}
