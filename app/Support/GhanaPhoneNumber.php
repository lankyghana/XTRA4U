<?php

namespace App\Support;

/**
 * Reusable Ghana phone number formatter/validator.
 *
 * XTRA4U stores numbers in local format (0XXXXXXXXX, 10 digits) but different
 * gateways expect different shapes on the wire:
 *   - Moolre expects local format (0XXXXXXXXX) — see MoolrePaymentService::normalizePhone().
 *   - Payaza expects international format without a leading "+" (233XXXXXXXXX, 12 digits).
 *
 * This class only handles the "valid Ghana mobile number" shape check + the two
 * representations above. It intentionally does not validate network-specific
 * prefixes (MTN/Telecel/AirtelTigo ranges change too often to hardcode safely).
 */
class GhanaPhoneNumber
{
    /**
     * Strip everything but digits and collapse to local format (0XXXXXXXXX).
     * Returns an empty string if the input can't be reduced to a plausible
     * Ghana mobile number shape.
     */
    public static function toLocal(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') {
            return '';
        }

        // +233XXXXXXXXX / 233XXXXXXXXX -> 0XXXXXXXXX
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        }

        // Bare 9-digit subscriber number (no leading 0) -> add it.
        if (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * Convert to the international format Payaza expects: 233XXXXXXXXX
     * (12 digits, no "+", no leading 0). Returns an empty string if the
     * input isn't a valid Ghana mobile number.
     */
    public static function toInternational(string $phone): string
    {
        $local = self::toLocal($phone);

        if (! self::isValidLocal($local)) {
            return '';
        }

        return '233'.substr($local, 1);
    }

    /**
     * True if the given string is already a valid local Ghana mobile number
     * (0XXXXXXXXX, 10 digits).
     */
    public static function isValidLocal(string $phone): bool
    {
        return (bool) preg_match('/^0\d{9}$/', $phone);
    }

    /**
     * True if the given string, once normalized, is a valid Ghana mobile
     * number in *either* local or international shape.
     */
    public static function isValid(string $phone): bool
    {
        return self::isValidLocal(self::toLocal($phone));
    }
}
