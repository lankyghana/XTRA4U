<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutSuccessPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(Vendor $vendor, string $status): Order
    {
        return Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 25.50,
            'vendor_id' => $vendor->id,
            'status' => $status,
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-001',
            'payment_gateway' => 'test',
        ]);
    }

    public function test_success_page_renders_with_the_redesigned_chrome(): void
    {
        $vendor = Vendor::factory()->create(['vendor_code' => 'VEND000001']);
        $order = $this->makeOrder($vendor, 'Processing');

        $response = $this->get(route('checkout.success', $order));

        $response->assertOk();
        $response->assertSee('Payment Successful!');
        $response->assertSee('Order #' . $order->id, false);
        $response->assertSee('GH₵ 25.50', false);
        $response->assertSee($order->recipient_phone_number);
        // Same design system as the rest of the storefront journey.
        $response->assertSee('x4-btn', false);
        $response->assertSee(route('storefront.vendor', ['vendor' => $vendor->vendor_code]), false);
        $response->assertSee(route('checkout.receipt', $order), false);
    }

    public function test_completed_order_shows_the_completed_progress_state(): void
    {
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'Completed');

        $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertSee('Completed')
            ->assertDontSee('This order needs attention');
    }

    public function test_failed_order_shows_the_needs_attention_notice_instead_of_next_steps(): void
    {
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'Failed');

        $response = $this->get(route('checkout.success', $order));

        $response->assertOk();
        $response->assertSee('This order needs attention');
        $response->assertDontSee('What happens next?');
    }
}
