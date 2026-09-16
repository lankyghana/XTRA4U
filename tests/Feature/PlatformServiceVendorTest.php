<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Support\PlatformServiceVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformServiceVendorTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    // -------------------------------------------------------------------------
    // Support class: assignment storage
    // -------------------------------------------------------------------------

    public function test_categories_are_unassigned_by_default(): void
    {
        foreach (PlatformServiceVendor::categories() as $category) {
            $this->assertNull(PlatformServiceVendor::vendorIdFor($category));
        }
    }

    public function test_set_vendor_for_persists_and_can_be_cleared(): void
    {
        $vendor = Vendor::factory()->create();

        PlatformServiceVendor::setVendorFor('data', $vendor->id);
        $this->assertSame($vendor->id, PlatformServiceVendor::vendorIdFor('data'));

        PlatformServiceVendor::setVendorFor('data', null);
        $this->assertNull(PlatformServiceVendor::vendorIdFor('data'));
    }

    public function test_assignments_returns_every_managed_category(): void
    {
        $vendor = Vendor::factory()->create();
        PlatformServiceVendor::setVendorFor('ecg', $vendor->id);

        $assignments = PlatformServiceVendor::assignments();

        $this->assertSame(PlatformServiceVendor::categories(), array_keys($assignments));
        $this->assertSame($vendor->id, $assignments['ecg']);
        $this->assertNull($assignments['data']);
    }

    // -------------------------------------------------------------------------
    // Support class: resolve() — never substitutes a different vendor
    // -------------------------------------------------------------------------

    public function test_resolve_returns_null_when_unconfigured(): void
    {
        $this->assertNull(PlatformServiceVendor::resolve('data'));
    }

    public function test_resolve_returns_the_configured_approved_vendor(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $resolved = PlatformServiceVendor::resolve('data');

        $this->assertNotNull($resolved);
        $this->assertSame($vendor->id, $resolved->id);
    }

    public function test_resolve_returns_null_when_configured_vendor_is_unapproved(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->assertNull(PlatformServiceVendor::resolve('data'));
    }

    public function test_resolve_returns_null_when_configured_vendor_is_deleted(): void
    {
        $vendor = Vendor::factory()->create();
        PlatformServiceVendor::setVendorFor('data', $vendor->id);
        $vendor->delete();

        $this->assertNull(PlatformServiceVendor::resolve('data'));
    }

    public function test_resolve_never_falls_back_to_a_different_vendor(): void
    {
        // A different, otherwise-valid approved vendor exists — resolve()
        // must still return null rather than substituting it.
        Vendor::factory()->create(['is_approved' => true]);

        $this->assertNull(PlatformServiceVendor::resolve('data'));
    }

    // -------------------------------------------------------------------------
    // Support class: eligibleVendors() — annotation, never hard-filtering
    // -------------------------------------------------------------------------

    public function test_eligible_vendors_annotates_product_category_without_hiding_vendors(): void
    {
        $withProducts = Vendor::factory()->create();
        $withoutProducts = Vendor::factory()->create();

        Product::create([
            'vendor_id' => $withProducts->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => 5.00,
            'is_active' => true,
        ]);

        $choices = PlatformServiceVendor::eligibleVendors('data');

        // Both approved vendors are present — eligibility is a hint, not a filter.
        $this->assertCount(2, $choices);

        $eligible = $choices->firstWhere(fn ($c) => $c['vendor']->id === $withProducts->id);
        $ineligible = $choices->firstWhere(fn ($c) => $c['vendor']->id === $withoutProducts->id);

        $this->assertTrue($eligible['eligible']);
        $this->assertNotNull($eligible['hint']);
        $this->assertFalse($ineligible['eligible']);
        $this->assertNull($ineligible['hint']);
    }

    public function test_eligible_vendors_annotates_results_checker_category(): void
    {
        $vendor = Vendor::factory()->create();
        $service = NetworkService::factory()->create(['service_type' => 'results_checker']);
        VendorResultCheckerSetting::create([
            'vendor_id' => $vendor->id,
            'service_id' => $service->id,
            'profit_amount' => 5.00,
            'is_active' => true,
        ]);

        $choices = PlatformServiceVendor::eligibleVendors('results');

        $this->assertTrue($choices->firstWhere(fn ($c) => $c['vendor']->id === $vendor->id)['eligible']);
    }

    public function test_eligible_vendors_annotates_afa_category(): void
    {
        $vendor = Vendor::factory()->create([
            'afa_enabled' => true,
            'afa_price' => 15.00,
        ]);

        $choices = PlatformServiceVendor::eligibleVendors('afa');

        $this->assertTrue($choices->firstWhere(fn ($c) => $c['vendor']->id === $vendor->id)['eligible']);
    }

    // -------------------------------------------------------------------------
    // Admin settings screen
    // -------------------------------------------------------------------------

    public function test_admin_page_loads(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.settings.platform-service-vendors'))
            ->assertStatus(200)
            ->assertSee('Platform Service Vendors');
    }

    public function test_guest_cannot_view_or_update_platform_service_vendors(): void
    {
        $this->get(route('admin.settings.platform-service-vendors'))
            ->assertRedirect(route('admin.login'));

        $vendor = Vendor::factory()->create();
        $this->put(route('admin.settings.platform-service-vendors.update'), [
            'vendor' => ['data' => $vendor->id],
        ])->assertRedirect(route('admin.login'));

        $this->assertNull(PlatformServiceVendor::vendorIdFor('data'));
    }

    public function test_admin_can_assign_a_vendor_to_a_category(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $this->put(route('admin.settings.platform-service-vendors.update'), [
            'vendor' => ['data' => $vendor->id],
        ])
            ->assertRedirect(route('admin.settings.platform-service-vendors'))
            ->assertSessionHas('success');

        $this->assertSame($vendor->id, PlatformServiceVendor::vendorIdFor('data'));
        $this->assertNull(PlatformServiceVendor::vendorIdFor('ecg'));
    }

    public function test_admin_can_clear_an_assignment(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->put(route('admin.settings.platform-service-vendors.update'), [
            'vendor' => ['data' => ''],
        ])->assertRedirect(route('admin.settings.platform-service-vendors'));

        $this->assertNull(PlatformServiceVendor::vendorIdFor('data'));
    }

    public function test_admin_cannot_assign_an_unapproved_vendor(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create(['is_approved' => false]);

        $this->put(route('admin.settings.platform-service-vendors.update'), [
            'vendor' => ['data' => $vendor->id],
        ])->assertSessionHasErrors('vendor.data');

        $this->assertNull(PlatformServiceVendor::vendorIdFor('data'));
    }

    public function test_admin_cannot_assign_a_nonexistent_vendor(): void
    {
        $this->actingAdmin();

        $this->put(route('admin.settings.platform-service-vendors.update'), [
            'vendor' => ['data' => 999999],
        ])->assertSessionHasErrors('vendor.data');

        $this->assertNull(PlatformServiceVendor::vendorIdFor('data'));
    }
}
