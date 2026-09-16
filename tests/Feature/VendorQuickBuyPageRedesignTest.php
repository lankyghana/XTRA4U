<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorQuickBuyPageRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_buy_page_renders_with_the_redesigned_chrome(): void
    {
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.quick-buy.show'));

        $response->assertOk();
        $response->assertSee('Quick Buy (Wallet)');
        // Now wrapped in <x-vendor-layout> — the shared dashboard sidebar
        // must be present so this no longer feels like a separate site.
        $response->assertSee('Vendor Portal');
        $response->assertSee('Dashboard');
        $response->assertSee('Products');
        // The balance-poll script matches elements by this exact attribute
        // prefix — it must survive the redesign unchanged.
        $response->assertSee('x-data=\'quickBuy(', false);
        $response->assertSee('action="' . route('vendor.quick-buy.store') . '"', false);
        $response->assertSee('topup-open', false);
    }
}
