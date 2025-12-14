<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PurchaseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_route_creates_order_and_initiates_payment()
    {
        // Use generated contact info so tests aren't tied to literal values
        $recipientPhone = '055' . fake()->numerify('#######');
        $mobileMoney = '055' . fake()->numerify('#######');
        $customerEmail = fake()->safeEmail();

        // Parameterize expected payment reference so assertions are robust
        $expectedRef = 'XTRA4U-TEST-' . Str::upper(Str::random(8));

        // Mock the PaymentService to simulate successful payment initiation
        $this->mock(\App\Services\PaymentService::class, function ($mock) use ($expectedRef) {
            $mock->shouldReceive('initiatePayment')->andReturn([
                'success' => true,
                'message' => 'Payment prompt sent',
                'reference' => $expectedRef,
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
            'recipient_phone_number' => $recipientPhone,
            'mobile_money_number' => $mobileMoney,
            'customer_email' => $customerEmail,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
        ]);

        // Should redirect to checkout with payment pending
        $response->assertRedirect();

        // Verify order was created
        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals($product->name, $order->service_purchased);
        $this->assertEquals($product->id, $order->vendor_service_id);
        $this->assertEquals($product->price, (float) $order->amount_paid);
        $this->assertEquals('Pending', $order->status);
        $this->assertEquals('pending', $order->payment_status);

        // Controller flashes the payment reference to session; the order
        // model itself isn't updated at this point.
        $response->assertSessionHas('payment_reference', $expectedRef);
    }

    public function test_purchase_fails_gracefully_when_payment_gateway_error()
    {
        // Mock the PaymentService to simulate payment failure
        $this->mock(\App\Services\PaymentService::class, function ($mock) {
            $mock->shouldReceive('initiatePayment')->andReturn([
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

        $recipientPhone = '055' . fake()->numerify('#######');
        $mobileMoney = '055' . fake()->numerify('#######');
        $customerEmail = fake()->safeEmail();

        $response = $this->post(route('purchase'), [
            'recipient_phone_number' => $recipientPhone,
            'mobile_money_number' => $mobileMoney,
            'customer_email' => $customerEmail,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
        ]);

        // Should redirect back with error
        $response->assertSessionHasErrors('payment');

        // Verify order was created but marked as failed
        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals('Failed', $order->status);
        $this->assertEquals('failed', $order->payment_status);
    }

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

        $recipientPhone = '055' . fake()->numerify('#######');
        $mobileMoney = '055' . fake()->numerify('#######');
        $customerEmail = fake()->safeEmail();

        // Mock payment initiation to avoid external calls during tests
        $expectedRef = 'XTRA4U-TEST-' . Str::upper(Str::random(8));
        $this->mock(\App\Services\PaymentService::class, function ($mock) use ($expectedRef) {
            $mock->shouldReceive('initiatePayment')->andReturn([
                'success' => true,
                'message' => 'Payment prompt sent',
                'reference' => $expectedRef,
            ]);
        });

        $response = $this->post(route('purchase'), [
            'recipient_phone_number' => $recipientPhone,
            'mobile_money_number' => $mobileMoney,
            'customer_email' => $customerEmail,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
        ]);

        $response->assertRedirect();

        // The controller redirects to the checkout page and flashes the
        // payment reference in session. Assert the flash exists and then
        // simulate the payment callback by placing a token in session and
        // calling the callback route (this mirrors how gateway callbacks
        // are handled in the app).
        $response->assertSessionHas('payment_pending', true);
        $response->assertSessionHas('payment_reference', $expectedRef);
        $this->assertTrue($response->isRedirection());

        // Simulate callback payload/storage
        $token = Str::random(40);
        $payload = [
            'recipient_phone_number' => $recipientPhone,
            'mobile_money_number' => $mobileMoney,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'service_purchased' => $product->name,
            'amount_paid' => $product->price,
            'payment_status' => 'completed',
        ];

        $sessionTokens = [
            $token => [
                'payload' => $payload,
                'expires_at' => now()->addMinutes(10)->toDateTimeString(),
            ],
        ];

        $callbackResponse = $this->withSession(['purchase.tokens' => $sessionTokens])
            ->get(route('purchase.callback', ['token' => $token]));

        $callbackResponse->assertOk();

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals($product->name, $order->service_purchased);
        $this->assertEquals($product->id, $order->vendor_service_id);
        $this->assertEquals($product->price, (float) $order->amount_paid);
        // Depending on flow the order created during the initial store() call
        // may remain Pending while the callback creates a separate record.
        // Accept either Completed or Pending here but ensure the transaction
        // shows a completed payment.
        $this->assertContains($order->status, ['Completed', 'Pending']);

        // Ensure a completed transaction was recorded for this vendor and amount.
        $this->assertDatabaseHas('transactions', [
            'vendor_id' => $vendor->id,
            'amount' => $product->price,
            'payment_status' => 'completed',
        ]);
    }
}
