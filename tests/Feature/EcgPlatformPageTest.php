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
 * redirect from the legacy /services/ecg URL, and the admin category switch.
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

    public function test_admin_closing_the_ecg_category_closes_utility_bills(): void
    {
        \App\Services\UtilityBills\UtilityBillSettings::save(true, null);
        ServiceAvailability::setOpen('ecg', false);
        ServiceAvailability::setMessage('ECG payments are paused for maintenance.');

        $this->get(route('services.utility-bills'))
            ->assertStatus(503)
            ->assertSee('ECG payments are paused for maintenance.');
    }

    public function test_vendor_storefront_still_loads(): void
    {
        $vendor = Vendor::factory()->create();

        $this->get(route('storefront.vendor', $vendor->vendor_code))->assertOk();
    }
}
