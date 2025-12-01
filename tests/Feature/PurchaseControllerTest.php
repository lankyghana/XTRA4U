<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_route_uses_product_price()
    {
        $vendor = Vendor::factory()->create();

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'Data Bundle',
            'description' => '1GB bundle',
            'price' => 150.50,
            'is_active' => true,
        ]);

        $response = $this->post(route('purchase'), [
            'recipient_phone_number' => '0550000000',
            'mobile_money_number' => '0550000001',
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'service_purchased' => 'tampered',
            'amount_paid' => 1,
        ]);

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/purchase/callback/', $location);

        $callbackResponse = $this->get($location);
        $callbackResponse->assertOk();

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals($product->name, $order->service_purchased);
        $this->assertEquals($product->id, $order->vendor_service_id);
        $this->assertEquals($product->price, (float) $order->amount_paid);
        $this->assertEquals('Completed', $order->status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'amount' => $product->price,
            'payment_status' => 'completed',
        ]);
    }
}
