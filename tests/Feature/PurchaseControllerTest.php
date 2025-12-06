<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\MoolrePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PurchaseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_route_creates_order_and_initiates_payment()
    {
        // Mock the MoolrePaymentService to simulate successful payment initiation
        $this->mock(MoolrePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('requestPayment')->andReturn([
                'success' => true,
                'message' => 'Payment prompt sent',
                'reference' => 'XTRA4U-TEST1234-1',
                'transaction_id' => 'moolre_123',
                'status' => 'pending',
            ]);
        });

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
        ]);

        // Should redirect to checkout with payment pending
        $response->assertRedirect();

        // Verify order was created
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals($product->name, $order->service_purchased);
        $this->assertEquals($product->id, $order->vendor_service_id);
        $this->assertEquals($product->price, (float) $order->amount_paid);
        $this->assertEquals('Pending', $order->status);
        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals('XTRA4U-TEST1234-1', $order->payment_reference);
    }

    public function test_purchase_fails_gracefully_when_payment_gateway_error()
    {
        // Mock the MoolrePaymentService to simulate payment failure
        $this->mock(MoolrePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('requestPayment')->andReturn([
                'success' => false,
                'message' => 'Insufficient balance',
                'reference' => null,
            ]);
        });

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
        ]);

        // Should redirect back with error
        $response->assertSessionHasErrors('payment');

        // Verify order was created but marked as failed
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals('Failed', $order->status);
        $this->assertEquals('failed', $order->payment_status);
    }
}
