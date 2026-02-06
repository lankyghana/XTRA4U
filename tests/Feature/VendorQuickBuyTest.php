<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Vendor;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VendorQuickBuyTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_buy_wallet_creates_order_with_mobile_money_fallback()
    {
        // Create vendor and product
        $vendor = Vendor::factory()->create(['wallet_balance' => 10.00]);
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'Test Product',
            'price' => 1.00,
            'is_active' => true,
        ]);

        // Create a completed wallet top-up so debit can consume it
        \App\Models\WalletTopup::create([
            'vendor_id' => $vendor->id,
            'reference' => 'test-topup-ref',
            'amount' => 10.00,
            'status' => 'completed',
            'consumed' => 0.00,
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        // Act as vendor
        $this->actingAs($vendor, 'vendor');

        // Post quick-buy with pay_with_wallet and no mobile_money_number
        $resp = $this->post(route('vendor.quick-buy.store'), [
            'product_id' => (string) $product->id,
            'recipient_phone_number' => '0559275699',
            'payment_method' => 'wallet',
        ]);

        $resp->assertStatus(302);

        $this->assertDatabaseHas('orders', [
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'mobile_money_number' => '0559275699',
            'payment_source' => 'wallet',
        ]);
    }
}
