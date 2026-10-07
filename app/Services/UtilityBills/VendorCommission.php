<?php

namespace App\Services\UtilityBills;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Vendor commission (XTRA4U -> vendor) for a Utility Bill sale.
 *
 * This is NOT the provider's commission to XTRA4U. It is computed only from the
 * terms frozen on the order at creation, in integer pesewas — no floats in the
 * result. Percentage values carry up to 4 decimals and are scaled to integers
 * before multiplying; the result is rounded half-up to the pesewa.
 */
final class VendorCommission
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const BASIS_BILL_FACE_VALUE = 'bill_face_value';

    /** Technical upper bounds enforced on admin input. */
    public const MAX_PERCENTAGE = '20';

    public const MAX_FIXED = '100';

    /**
     * @return string decimal(…,2) amount in GHS, e.g. "1.00"
     */
    public static function calculate(?string $type, int|float|string|null $value, int|float|string|null $basisAmount): string
    {
        $basisMinor = Money::minor($basisAmount);

        if ($basisMinor <= 0 || $value === null || ! is_numeric($value)) {
            return '0.00';
        }

        switch ($type) {
            case self::TYPE_PERCENTAGE:
                $scaled = self::scaled($value, 4); // percent * 10^4
                if ($scaled <= 0) {
                    return '0.00';
                }
                // minor * (percent/100) = minor * scaled / 10^6, rounded half-up.
                $minor = intdiv($basisMinor * $scaled + 500000, 1000000);
                break;

            case self::TYPE_FIXED:
                $minor = Money::minor($value);
                // A fixed commission can never exceed the bill it was earned on.
                $minor = min($minor, $basisMinor);
                break;

            default:
                return '0.00';
        }

        return number_format(max(0, $minor) / Money::MINOR_UNITS, 2, '.', '');
    }

    /** Exact decimal-string -> scaled integer (no float multiplication). */
    private static function scaled(int|float|string $value, int $decimals): int
    {
        $string = is_string($value) ? trim($value) : number_format((float) $value, $decimals, '.', '');

        if (! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw new InvalidArgumentException('Invalid commission value.');
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');
        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);

        return (int) ($whole.$fraction);
    }

    public static function isValidType(?string $type): bool
    {
        return in_array($type, [self::TYPE_PERCENTAGE, self::TYPE_FIXED], true);
    }
}
