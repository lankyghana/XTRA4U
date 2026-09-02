<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProductCreatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_product_page_renders_inside_the_dashboard_shell(): void
    {
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.products.create'));

        $response->assertOk();
        $response->assertSee('Create Product');
        // Now wrapped in <x-vendor-layout> — the sidebar nav must be present.
        $response->assertSee('Vendor Portal');
        $response->assertSee('action="' . route('product.store') . '"', false);
        foreach (['name="name"', 'name="network"', 'name="category"', 'name="price"', 'name="image"', 'id="external-services-config"'] as $needle) {
            $response->assertSee($needle, false);
        }
    }

    public function test_page_includes_the_live_preview_card_and_its_script(): void
    {
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.products.create'));

        $response->assertOk();
        $response->assertSee('Storefront Preview');
        foreach (['id="product_preview_card"', 'id="preview_name"', 'id="preview_price"', 'id="preview_size"', 'id="preview_tag"'] as $needle) {
            $response->assertSee($needle, false);
        }
        // The decorative preview script must read the real field ids, not
        // reinvent them.
        $response->assertSee("\$('name')", false);
        $response->assertSee("\$('price')", false);
    }

    public function test_submitting_the_create_form_still_creates_a_product(): void
    {
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($vendor, 'vendor')->post(route('product.store'), [
            'name' => 'MTN 10GB',
            'price' => 45.00,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('products', [
            'vendor_id' => $vendor->id,
            'name' => 'MTN 10GB',
        ]);
    }
}
