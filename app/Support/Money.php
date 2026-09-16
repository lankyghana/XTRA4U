<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal-safe money handling for the platform's single currency (GHS).
 *
 * Why this exists: every financial comparison in the payment pipeline used to
 * be written as `round((float) $a, 2) < round((float) $b, 2)`. That is correct
 * often enough to hide the fact that it is comparing IEEE-754 doubles, and it
 * silently encourages `<`/`>=` semantics where the business rule is actually
 * "exactly equal". Both problems are fixed by converting to integer MINOR
 * UNITS (pesewas) once, at the boundary, and comparing integers from then on.
 *
 * Convention for this codebase: money is STORED as `decimal(10,2)` columns and
 * read back by Eloquent as `decimal:2`-cast strings. Nothing here changes that
 * storage convention — this class is only how those values are COMPARED. Do
 * not introduce a second representation (float cents, BCMath strings, a Money
 * value object on models) elsewhere; convert at the comparison site with
 * {@see Money::minor()} and keep the column semantics as they are.
 */
final class Money
{
    /** Minor units per major unit for GHS (100 pesewas = 1 cedi). */
    public const MINOR_UNITS = 100;

    /**
     * Convert a monetary value to integer minor units (pesewas).
     *
     * Accepts anything the codebase actually holds money in: an Eloquent
     * `decimal:2` string ("89.00"), a float, an int, or null. The value is
     * rounded to the nearest minor unit — `round()` is applied AFTER scaling
     * specifically so that binary-representation drift (the classic
     * 0.1 * 100 === 10.000000000000002) can never leak into a comparison.
     *
     * @throws InvalidArgumentException for non-numeric or non-finite input —
     *                                  a financial comparison must never silently treat garbage as 0.
     */
    public static function minor(int|float|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Non-numeric monetary value: '.var_export($amount, true));
        }

        $value = (float) $amount;

        if (! is_finite($value)) {
            throw new InvalidArgumentException('Non-finite monetary value: '.var_export($amount, true));
        }

        return (int) round($value * self::MINOR_UNITS);
    }

    /**
     * Exact equality in minor units. This is the comparison the payment
     * integrity invariant uses: a gateway-confirmed amount must match the
     * order's frozen expected amount EXACTLY — not "at least", not "close".
     */
    public static function equals(int|float|string|null $a, int|float|string|null $b): bool
    {
        return self::minor($a) === self::minor($b);
    }

    /** Standard spaceship comparison in minor units: -1, 0, or 1. */
    public static function compare(int|float|string|null $a, int|float|string|null $b): int
    {
        return self::minor($a) <=> self::minor($b);
    }

    public static function isLessThan(int|float|string|null $a, int|float|string|null $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function isGreaterThan(int|float|string|null $a, int|float|string|null $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function isPositive(int|float|string|null $a): bool
    {
        return self::minor($a) > 0;
    }

    /** Signed difference (a - b) expressed in major units, rounded to 2dp. */
    public static function difference(int|float|string|null $a, int|float|string|null $b): float
    {
        return round((self::minor($a) - self::minor($b)) / self::MINOR_UNITS, 2);
    }

    /** Normalize to a canonical 2-decimal string suitable for a decimal(10,2) column. */
    public static function toDecimalString(int|float|string|null $amount): string
    {
        return number_format(self::minor($amount) / self::MINOR_UNITS, 2, '.', '');
    }

    /** Sum a list of monetary values without accumulating float drift. */
    public static function sum(int|float|string|null ...$amounts): string
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += self::minor($amount);
        }

        return number_format($total / self::MINOR_UNITS, 2, '.', '');
    }
}
