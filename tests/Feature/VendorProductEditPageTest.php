<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProductEditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_product_page_renders_inside_the_dashboard_shell_with_current_values(): void
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 10GB',
            'price' => 45.00,
            'description' => json_encode(['size' => '10GB', 'validity' => '30 days', 'tag' => 'Best Value']),
            'is_active' => true,
        ]);

        $response = $this->actingAs($vendor, 'vendor')->get(route('product.edit', $product->id));

        $response->assertOk();
        $response->assertSee('Edit Product');
        // Now wrapped in <x-vendor-layout> — the sidebar nav must be present.
        $response->assertSee('Vendor Portal');
        $response->assertSee('action="' . route('product.update', $product->id) . '"', false);
        $response->assertSee('value="MTN 10GB"', false);
        $response->assertSee('value="45.00"', false);
        $response->assertSee('id="external-services-config"', false);
        // Preview seeded from the product's current values.
        $response->assertSee('id="preview_name"', false);
        $response->assertSee('Best Value');
    }

    public function test_submitting_the_edit_form_still_updates_the_product(): void
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 10GB',
            'price' => 45.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($vendor, 'vendor')->put(route('product.update', $product->id), [
            'name' => 'MTN 15GB',
            'price' => 55.00,
            'is_active' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'MTN 15GB',
        ]);
    }
}
