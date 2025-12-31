<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVendorAffiliateManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_can_see_vendor_affiliate_mapping_on_vendors_page(): void
    {
        $this->actingAdmin();

        $upline = Vendor::factory()->create([
            'name' => 'Upline Vendor',
        ]);

        Vendor::factory()->create([
            'name' => 'Downline Vendor',
            'affiliate_vendor_id' => $upline->id,
        ]);

        $response = $this->get(route('admin.vendors.index'));

        $response->assertOk();
        $response->assertSee('Downline Vendor');
        $response->assertSee('Affiliate of:');
        $response->assertSee('Upline Vendor');
    }

    public function test_admin_can_disable_affiliate_relationship_between_two_vendors(): void
    {
        $this->actingAdmin();

        $upline = Vendor::factory()->create();

        $downline = Vendor::factory()->create([
            'affiliate_vendor_id' => $upline->id,
        ]);

        $response = $this->post(route('admin.vendors.disable-affiliate', $downline));

        $response->assertRedirect();
        $this->assertNull($downline->fresh()->affiliate_vendor_id);
    }
}
