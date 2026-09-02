<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_status_page_renders_with_the_redesigned_chrome(): void
    {
        $response = $this->get(route('order.status'));

        $response->assertOk();
        $response->assertSee('Check Order Status');
        $response->assertSee('orderStatusChecker()', false);
        // Same design system as the rest of the storefront journey.
        $response->assertSee('x4-btn', false);
        $response->assertSee(route('order.status.check'), false);
        $response->assertSee(route('order.status.poll'), false);
    }

    public function test_shop_now_links_point_at_the_flagship_store(): void
    {
        $vendor = Vendor::factory()->create(['vendor_code' => 'FLAG000001', 'is_approved' => true]);
        config(['storefront.main_store_code' => $vendor->vendor_code]);

        $this->get(route('order.status'))
            ->assertOk()
            ->assertSee(route('storefront.vendor', ['vendor' => $vendor->vendor_code]), false);
    }
}
