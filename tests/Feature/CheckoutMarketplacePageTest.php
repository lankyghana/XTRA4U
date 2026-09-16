<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutMarketplacePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_page_renders_with_the_redesigned_chrome_and_the_purchase_flow_intact(): void
    {
        config(['storefront.checkout_coming_soon' => false]);

        $vendor = Vendor::factory()->create();
        Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 5GB',
            'price' => 25.50,
            'is_active' => true,
        ]);

        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertSee('Marketplace');
        $response->assertSee('x4-btn', false);
        // The purchase form, empty state, and inline payment manager were
        // previously dropped from every render by a stray @endsection
        // pasted mid-template — confirm all three are present now.
        $response->assertSee('action="' . route('purchase') . '"', false);
        $response->assertSee('No services found');
        $response->assertSee('InlinePaymentManager', false);
        $response->assertSee('id="products-data"', false);
    }

    public function test_coming_soon_page_still_shows_when_the_flag_is_enabled(): void
    {
        config(['storefront.checkout_coming_soon' => true]);

        $this->get(route('checkout.show'))
            ->assertOk()
            ->assertSee('Marketplace')
            ->assertDontSee('action="' . route('purchase') . '"', false);
    }
}
