<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorAfaResellerProviderRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_only_select_affiliate_parent_as_afa_provider(): void
    {
        $parent = Vendor::factory()->create([
            'is_approved' => true,
            'afa_enabled' => true,
            'afa_price' => 50,
        ]);

        $otherProvider = Vendor::factory()->create([
            'is_approved' => true,
            'afa_enabled' => true,
            'afa_price' => 60,
        ]);

        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => $parent->id,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->put(route('vendor.afa.update-settings'), [
            'afa_mode' => 'reseller',
            'afa_source_vendor_id' => $otherProvider->id,
            'afa_markup' => 10,
        ]);

        $response->assertSessionHas('error');

        $vendor->refresh();
        $this->assertFalse((bool) $vendor->afa_reseller_enabled);
    }

    public function test_vendor_without_affiliate_parent_cannot_enable_reseller_mode(): void
    {
        $provider = Vendor::factory()->create([
            'is_approved' => true,
            'afa_enabled' => true,
            'afa_price' => 50,
        ]);

        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => null,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->put(route('vendor.afa.update-settings'), [
            'afa_mode' => 'reseller',
            'afa_source_vendor_id' => $provider->id,
            'afa_markup' => 10,
        ]);

        $response->assertSessionHas('error');

        $vendor->refresh();
        $this->assertFalse((bool) $vendor->afa_reseller_enabled);
    }
}
