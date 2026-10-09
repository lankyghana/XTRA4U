<?php

namespace Tests\Unit;

use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\ProviderStatusMapper;
use App\Services\UtilityBills\VendorCommission;
use PHPUnit\Framework\TestCase;

class VendorCommissionTest extends TestCase
{
    public function test_percentage_uses_exact_integer_math(): void
    {
        $this->assertSame('1.00', VendorCommission::calculate('percentage', '1.0000', '100.00'));
        $this->assertSame('0.50', VendorCommission::calculate('percentage', '0.5000', '100.00'));
        $this->assertSame('0.40', VendorCommission::calculate('percentage', '0.4000', '100.00'));
        // 0.29 * 100 = 28.999999999999996 in floats; the integer path must give 0.29.
        $this->assertSame('0.29', VendorCommission::calculate('percentage', '0.29', '100.00'));
        // 1.1% of 33.33 = 0.36663 -> 0.37 (half-up)
        $this->assertSame('0.37', VendorCommission::calculate('percentage', '1.1', '33.33'));
        // 0.005% of 1.00 = 0.00005 -> 0.00
        $this->assertSame('0.00', VendorCommission::calculate('percentage', '0.005', '1.00'));
    }

    public function test_fixed_is_flat_and_capped_at_the_bill(): void
    {
        $this->assertSame('1.00', VendorCommission::calculate('fixed', '1.0000', '65.00'));
        $this->assertSame('2.50', VendorCommission::calculate('fixed', '2.5', '1000.00'));
        $this->assertSame('1.00', VendorCommission::calculate('fixed', '5.00', '1.00'));
    }

    public function test_zero_or_unknown_terms_earn_nothing(): void
    {
        $this->assertSame('0.00', VendorCommission::calculate('percentage', '0', '100.00'));
        $this->assertSame('0.00', VendorCommission::calculate('fixed', '0', '100.00'));
        $this->assertSame('0.00', VendorCommission::calculate(null, '1', '100.00'));
        $this->assertSame('0.00', VendorCommission::calculate('percentage', null, '100.00'));
        $this->assertSame('0.00', VendorCommission::calculate('percentage', '1', '0'));
        $this->assertSame('0.00', VendorCommission::calculate('bogus', '1', '100.00'));
    }

    public function test_provider_status_mapping_is_centralised(): void
    {
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, ProviderStatusMapper::toFulfillmentStatus('pending'));
        $this->assertSame(FulfillmentStatus::PROVIDER_PROCESSING, ProviderStatusMapper::toFulfillmentStatus('Processing'));
        $this->assertSame(FulfillmentStatus::COMPLETED, ProviderStatusMapper::toFulfillmentStatus('completed'));
        $this->assertSame(FulfillmentStatus::FAILED, ProviderStatusMapper::toFulfillmentStatus('failed'));
        $this->assertSame(FulfillmentStatus::PROVIDER_REFUNDED, ProviderStatusMapper::toFulfillmentStatus('refunded'));
        // An unrecognised status is never success.
        $this->assertNull(ProviderStatusMapper::toFulfillmentStatus('success'));
        $this->assertNull(ProviderStatusMapper::toFulfillmentStatus(null));
    }
}
