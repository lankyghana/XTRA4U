<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /services/utility-bills is now the global, provider-backed Utility Bills
 * service (see tests/Feature/UtilityBills for its behaviour). This file keeps
 * the URL-level guarantees that predate it: the canonical path, the permanent
 * redirect from the legacy /services/ecg URL, and that the legacy "ecg" category toggle is NOT
 * a second Utility Bills switch.
 *
 * The earlier vendor-product catalog behaviour of this page (assigned platform
 * vendor, vendor ECG products, product checkout) no longer applies: Utility
 * Bills is not a Product/ResellerProduct service. Vendor-created ECG products
 * remain on the vendor storefront catalog, which is unchanged.
 */
class EcgPlatformPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_utility_bills_is_served_at_its_canonical_url(): void
    {
        $this->assertSame('/services/utility-bills', route('services.utility-bills', [], false));

        // Closed by default until an admin enables the service: a friendly 503, never an error page.
        $this->get('/services/utility-bills')
            ->assertStatus(503)
            ->assertSee('Utility Bills Unavailable');
    }

    public function test_legacy_ecg_url_permanently_redirects_to_utility_bills(): void
    {
        $this->get('/services/ecg')
            ->assertStatus(301)
            ->assertRedirect(route('services.utility-bills'));
    }

    public function test_legacy_ecg_redirect_preserves_the_query_string(): void
    {
        $this->get('/services/ecg?reference=ABC123')
            ->assertStatus(301)
            ->assertRedirect(route('services.utility-bills', ['reference' => 'ABC123']));
    }

    public function test_the_legacy_ecg_category_toggle_does_not_control_utility_bills(): void
    {
        $availability = app(\App\Services\UtilityBills\UtilityBillAvailability::class);

        // Closing the legacy ECG product category leaves an enabled Utility Bills service open...
        \App\Services\UtilityBills\UtilityBillSettings::save(true, null);
        ServiceAvailability::setOpen('ecg', false);
        ServiceAvailability::setMessage('ECG payments are paused for maintenance.');
        $this->assertTrue($availability->serviceOpen());

        // ...and Utility Bills' own switch is the only thing that closes it.
        \App\Services\UtilityBills\UtilityBillSettings::save(false, 'Back at 6pm');
        $this->assertFalse($availability->serviceOpen());
        $this->get(route('services.utility-bills'))->assertStatus(503)->assertSee('Back at 6pm')->assertDontSee('ECG payments are paused');
    }

    public function test_service_availability_page_labels_the_ecg_toggle_as_legacy_products(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']));

        $this->get(route('admin.settings.service-availability'))->assertOk()
            ->assertSee('ECG (legacy vendor products)')
            ->assertSee('Does not affect Utility Bills')
            ->assertSee(route('admin.utility-bills.settings'), false);
    }

    public function test_vendor_storefront_still_loads(): void
    {
        $vendor = Vendor::factory()->create();

        $this->get(route('storefront.vendor', $vendor->vendor_code))->assertOk();
    }
}
