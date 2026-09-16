<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Money is the decimal-safe comparison used by every financial invariant, so
 * the cases that make float comparison unsafe are pinned here directly.
 */
class MoneyTest extends TestCase
{
    public function test_values_that_are_unequal_as_floats_compare_correctly_in_minor_units(): void
    {
        // 0.1 + 0.2 === 0.30000000000000004 as a float.
        $this->assertTrue(Money::equals(0.1 + 0.2, '0.30'));
        $this->assertSame(30, Money::minor(0.1 + 0.2));

        // 1.005 and friends: scaling before rounding is what keeps these exact.
        $this->assertSame(10, Money::minor(0.1));
        $this->assertSame(8900, Money::minor('89.00'));
        $this->assertSame(8900, Money::minor(89));
        $this->assertSame(420, Money::minor('4.20'));
    }

    public function test_eloquent_decimal_strings_and_floats_are_interchangeable(): void
    {
        // Eloquent's decimal:2 cast hands back strings; callers pass floats.
        $this->assertTrue(Money::equals('89.00', 89.0));
        $this->assertTrue(Money::equals('0.10', 0.1));
        $this->assertFalse(Money::equals('89.00', 89.01));
    }

    public function test_the_incident_comparison_is_unambiguous(): void
    {
        // Order #83/#104: expected ~GHS 89, confirmed GHS 0.10.
        $this->assertFalse(Money::equals('0.10', '89.00'));
        $this->assertTrue(Money::isLessThan('0.10', '89.00'));
        $this->assertSame(-88.90, Money::difference('0.10', '89.00'));
    }

    public function test_null_and_empty_are_zero_but_garbage_is_rejected(): void
    {
        $this->assertSame(0, Money::minor(null));
        $this->assertSame(0, Money::minor(''));

        // A financial comparison must never silently treat nonsense as zero.
        $this->expectException(InvalidArgumentException::class);
        Money::minor('not-a-number');
    }

    public function test_sums_do_not_accumulate_drift(): void
    {
        $this->assertSame('89.00', Money::sum('88.00', '1.00'));
        $this->assertSame('0.30', Money::sum(0.1, 0.2));
        $this->assertSame('10.20', Money::sum('10.00', '0.20'));

        // A hundred ten-pesewa additions must be exactly GHS 10.00.
        $parts = array_fill(0, 100, 0.1);
        $this->assertSame('10.00', Money::sum(...$parts));
    }

    public function test_canonical_decimal_string_matches_the_column_format(): void
    {
        $this->assertSame('89.00', Money::toDecimalString(89));
        $this->assertSame('0.10', Money::toDecimalString('0.1'));
        $this->assertSame('1234.57', Money::toDecimalString(1234.567));
    }
}
