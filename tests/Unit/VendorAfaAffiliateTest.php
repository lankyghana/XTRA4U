<?php

namespace Tests\Unit;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorAfaAffiliateTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_affiliate_vendor_id_marks_vendor_as_afa_affiliate(): void
    {
        $parent = Vendor::factory()->create();

        $child = Vendor::factory()->create([
            'is_afa_affiliate' => false,
        ]);

        $child->affiliate_vendor_id = $parent->id;
        $child->save();

        $this->assertTrue($child->fresh()->is_afa_affiliate);
    }

    public function test_create_with_affiliate_vendor_id_is_idempotently_marked_as_afa_affiliate(): void
    {
        $parent = Vendor::factory()->create();

        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => $parent->id,
            'is_afa_affiliate' => false,
        ]);

        $this->assertTrue($vendor->fresh()->is_afa_affiliate);
    }
}
