<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The "Vendor Dashboard" storefront button is a convenience shortcut for a
 * vendor viewing their own public store — never an authorization boundary.
 * `vendor.dashboard` itself stays protected by the `vendor.approved`
 * middleware regardless of what this button does or doesn't show.
 *
 * Note: the storefront footer's own "Vendor Dashboard" link (general site
 * navigation) is deliberately suppressed on a vendor's own storefront page
 * (vendor_store.blade.php passes show-vendor-dashboard-link="false") for
 * every visitor, since the header already carries the ownership-gated
 * button there — see components/storefront/footer.blade.php. So on this
 * route the footer never contributes a "Vendor Dashboard" mention; these
 * tests count occurrences instead: 0 when the header button is hidden, 2
 * (desktop nav + mobile nav) when it's shown.
 */
class VendorStoreOwnershipDashboardButtonTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardMentionCount(TestResponse $response): int
    {
        return substr_count($response->getContent(), 'Vendor Dashboard');
    }

    public function test_owner_visiting_their_own_storefront_sees_the_dashboard_button(): void
    {
        $vendorA = Vendor::factory()->create([
            'vendor_code' => 'VENDORA001',
            'is_approved' => true,
        ]);

        $this->actingAs($vendorA, 'vendor');

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendorA->vendor_code]));

        $response->assertOk();
        $response->assertSee(route('vendor.dashboard'), false);
        // Footer link is suppressed on this page; desktop nav button + mobile nav button remain.
        $this->assertSame(2, $this->dashboardMentionCount($response));
    }

    public function test_vendor_visiting_another_vendors_storefront_does_not_see_the_dashboard_button(): void
    {
        $vendorA = Vendor::factory()->create([
            'vendor_code' => 'VENDORA002',
            'is_approved' => true,
        ]);
        $vendorB = Vendor::factory()->create([
            'vendor_code' => 'VENDORB002',
            'is_approved' => true,
        ]);

        $this->actingAs($vendorA, 'vendor');

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendorB->vendor_code]));

        $response->assertOk();
        // The footer link is suppressed on this page and the header's
        // ownership-gated button must not appear on someone else's store.
        $this->assertSame(0, $this->dashboardMentionCount($response));
    }

    public function test_guest_visiting_a_storefront_does_not_see_the_dashboard_button(): void
    {
        $vendor = Vendor::factory()->create([
            'vendor_code' => 'VENDORC003',
            'is_approved' => true,
        ]);

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]));

        $response->assertOk();
        $this->assertSame(0, $this->dashboardMentionCount($response));
    }

    public function test_owner_can_follow_the_button_to_a_working_dashboard_and_stays_authenticated(): void
    {
        $vendor = Vendor::factory()->create([
            'vendor_code' => 'VENDORD004',
            'is_approved' => true,
        ]);

        $this->actingAs($vendor, 'vendor');

        // The storefront button links to the named dashboard route; follow
        // it as the same request flow would.
        $storeResponse = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]));
        $storeResponse->assertOk();
        $storeResponse->assertSee(route('vendor.dashboard'), false);

        $dashboardResponse = $this->get(route('vendor.dashboard'));
        $dashboardResponse->assertOk();

        $this->assertTrue(auth('vendor')->check());
        $this->assertSame($vendor->id, auth('vendor')->id());
    }

    public function test_admin_visiting_a_storefront_does_not_see_the_dashboard_button_merely_from_admin_auth(): void
    {
        $vendor = Vendor::factory()->create([
            'vendor_code' => 'VENDORE005',
            'is_approved' => true,
        ]);
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin');

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]));

        $response->assertOk();
        $this->assertSame(0, $this->dashboardMentionCount($response));
    }

    public function test_owner_sees_the_dashboard_button_in_both_desktop_and_mobile_nav_markup(): void
    {
        $vendor = Vendor::factory()->create([
            'vendor_code' => 'VENDORF006',
            'is_approved' => true,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]));

        $response->assertOk();
        $html = $response->getContent();

        // The mobile nav block (#x4-mobile-nav) must contain its own copy
        // of the button, not just the desktop one.
        $mobileNavStart = strpos($html, 'id="x4-mobile-nav"');
        $headerEnd = strpos($html, '</header>');
        $this->assertNotFalse($mobileNavStart);
        $this->assertNotFalse($headerEnd);

        $mobileNavMarkup = substr($html, $mobileNavStart, $headerEnd - $mobileNavStart);
        $this->assertStringContainsString('Vendor Dashboard', $mobileNavMarkup);

        // Footer link is suppressed on this page; desktop nav + mobile nav remain.
        $this->assertSame(2, $this->dashboardMentionCount($response));
    }
}
