<?php

namespace App\Services\UtilityBills;

/**
 * The ONE place KiNG FLEXY order status strings are interpreted.
 *
 * Provider lifecycle: pending -> processing -> completed | failed | refunded.
 * Anything else maps to null ("unrecognised"), and callers must then leave the
 * order exactly as it is — an unknown string is never treated as success.
 */
final class ProviderStatusMapper
{
    public static function toFulfillmentStatus(?string $providerStatus): ?string
    {
        return match (strtolower(trim((string) $providerStatus))) {
            'pending' => FulfillmentStatus::PROVIDER_PENDING,
            'processing' => FulfillmentStatus::PROVIDER_PROCESSING,
            'completed' => FulfillmentStatus::COMPLETED,
            'failed' => FulfillmentStatus::FAILED,
            'refunded' => FulfillmentStatus::PROVIDER_REFUNDED,
            default => null,
        };
    }

    public static function normalize(?string $providerStatus): ?string
    {
        $value = strtolower(trim((string) $providerStatus));

        return in_array($value, ['pending', 'processing', 'completed', 'failed', 'refunded'], true) ? $value : null;
    }
}
