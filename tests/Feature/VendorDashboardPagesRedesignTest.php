<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDashboardPagesRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_page_renders_with_the_redesigned_chrome(): void
    {
        $vendor = Vendor::factory()->create();
        Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 5GB',
            'price' => 25.50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.products.index'));

        $response->assertOk();
        $response->assertSee('MTN 5GB');
        $response->assertSee('Add New Product');
        $response->assertSee(route('vendor.products.create'), false);
    }

    public function test_orders_page_renders_with_the_redesigned_chrome(): void
    {
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.orders.index'));

        $response->assertOk();
        $response->assertSee('My Orders');
        $response->assertSee('Affiliate Orders');
        $response->assertSee(route('vendor.orders.affiliate'), false);
    }
}
