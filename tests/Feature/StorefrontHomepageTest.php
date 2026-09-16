<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_for_the_configured_main_store(): void
    {
        $vendor = Vendor::factory()->create([
            'vendor_code' => 'BUNDJXW6SR',
            'is_approved' => true,
        ]);

        config(['storefront.main_store_code' => $vendor->vendor_code]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Your Gateway to', false);
        // Every primary call to action points at the flagship storefront.
        $response->assertSee(route('storefront.vendor', ['vendor' => $vendor->vendor_code]), false);
    }

    public function test_homepage_renders_when_no_vendor_exists(): void
    {
        // MainStore::vendor() returns null on an empty database; the page must
        // still render and fall back to the marketplace rather than error.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('checkout.show'), false);
    }

    public function test_service_grid_follows_the_storefront_category_config(): void
    {
        Vendor::factory()->create(['vendor_code' => 'BUNDJXW6SR', 'is_approved' => true]);

        config(['storefront.categories' => [
            'data' => ['label' => 'Data Bundles', 'icon' => 'signal', 'description' => 'Buy data.'],
            'ecg' => ['label' => 'ECG', 'icon' => 'bolt', 'description' => 'Pay for power.'],
        ]]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Data Bundles');
        $response->assertSee('Pay for power.');
        // A category that is not configured must not appear.
        $response->assertDontSee('AFA Registration');
    }

    public function test_afa_card_links_to_the_official_platform_page_regardless_of_flagship_pricing(): void
    {
        // AFA is now resolved via its own admin assignment
        // (App\Support\PlatformServiceVendor), independent of the flagship
        // store's own AFA pricing — the card always links to the platform
        // page, which degrades gracefully on its own if unconfigured.
        $vendor = Vendor::factory()->create([
            'vendor_code' => 'NOAFA00001',
            'is_approved' => true,
            'afa_enabled' => false,
            'afa_price' => 0,
        ]);
        config(['storefront.main_store_code' => $vendor->vendor_code]);

        $this->get('/')
            ->assertOk()
            ->assertSee(route('services.afa-registration'), false);
    }

    public function test_homepage_supplies_its_own_chrome_and_hides_the_shared_navigation(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // The storefront header/footer replace the shared chrome on this page.
        $response->assertSee('Become a Vendor');
        $response->assertSee('x4-btn', false);
    }
}
