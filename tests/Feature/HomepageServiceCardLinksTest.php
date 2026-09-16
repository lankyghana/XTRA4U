<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Vendor;
use App\Support\PlatformServiceVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageServiceCardLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_data_bundles_card_links_to_the_platform_page(): void
    {
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee(route('services.data-bundles'));
    }

    public function test_homepage_ecg_card_links_to_the_platform_page(): void
    {
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee(route('services.ecg'));
    }

    public function test_homepage_shop_card_links_to_the_platform_page(): void
    {
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee(route('services.shop'));
    }

    public function test_homepage_results_checker_card_links_to_the_platform_page(): void
    {
        // Goes through the legacy result-checkers.entry redirect, which now
        // forwards straight to the platform page (see StorefrontController).
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee(route('result-checkers.entry'));

        $this->get(route('result-checkers.entry'))
            ->assertRedirect(route('services.result-checkers'));
    }

    public function test_homepage_afa_card_links_to_the_platform_page(): void
    {
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee(route('services.afa-registration'));
    }

    public function test_homepage_still_links_to_the_flagship_vendor_store_for_the_generic_buy_now_cta(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        config(['storefront.main_store_code' => $vendor->vendor_code]);

        $shopUrl = route('storefront.vendor', ['vendor' => $vendor->vendor_code]);

        // Every category card now has a dedicated platform page, but the
        // header/hero "Buy Now" CTA is intentionally general-purpose and
        // still opens the flagship vendor's full storefront, unchanged.
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee($shopUrl);
    }

    public function test_full_customer_journey_from_homepage_to_data_bundles_purchase_page(): void
    {
        $vendor = Vendor::factory()->create();
        Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB Bundle',
            'description' => json_encode(['category' => 'data', 'network' => 'MTN', 'size' => '1GB']),
            'price' => 5.00,
            'is_active' => true,
        ]);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        // 1) Homepage exposes the dedicated Data Bundles link.
        $homepage = $this->get(route('storefront.index'));
        $homepage->assertOk()->assertSee(route('services.data-bundles'));

        // 2) Following it lands on a page scoped to that one vendor's data
        // products — not the vendor's full, multi-category storefront.
        $this->get(route('services.data-bundles'))
            ->assertOk()
            ->assertSee('Data Bundles')
            ->assertSee('MTN 1GB Bundle');
    }

    public function test_homepage_data_bundles_card_link_degrades_gracefully_when_unconfigured(): void
    {
        // No admin assignment exists — the homepage link itself must still
        // render (it points at a stable route, not a vendor-specific URL);
        // the graceful-unavailable behaviour is exercised by the target page.
        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee(route('services.data-bundles'));

        $this->get(route('services.data-bundles'))
            ->assertStatus(503);
    }
}
