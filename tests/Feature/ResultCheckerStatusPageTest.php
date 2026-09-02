<?php

namespace Tests\Feature;

use App\Models\ResultCheckerOrder;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultCheckerStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_search_page_renders_with_the_redesigned_chrome(): void
    {
        $response = $this->get(route('result-checkers.status'));

        $response->assertOk();
        $response->assertSee('Check Result Status');
        $response->assertSee('resultCheckerStatusChecker()', false);
        // Same design system as the rest of the storefront journey.
        $response->assertSee('x4-btn', false);
        $response->assertSee(route('result-checkers.status.check'), false);
    }

    public function test_shop_now_links_point_at_the_flagship_store(): void
    {
        $vendor = Vendor::factory()->create(['vendor_code' => 'FLAG000002', 'is_approved' => true]);
        config(['storefront.main_store_code' => $vendor->vendor_code]);

        $this->get(route('result-checkers.status'))
            ->assertOk()
            ->assertSee(route('storefront.vendor', ['vendor' => $vendor->vendor_code]), false);
    }

    public function test_single_order_status_page_renders_the_correct_status_and_data(): void
    {
        $order = ResultCheckerOrder::factory()->create([
            'customer_name' => 'Kwame Mensah',
            'customer_phone' => '0551234567',
            'status' => 'completed',
            'total_price' => 15.00,
            'payment_reference' => 'RC-TEST-001',
            'paid_at' => now()->subMinutes(10),
            'fulfilled_at' => now()->subMinutes(2),
        ]);

        $response = $this->get(route('result-checkers.status.show', $order));

        $response->assertOk();
        $response->assertSee('Order Status');
        $response->assertSee('Completed');
        $response->assertSee('Kwame Mensah');
        $response->assertSee('0551234567');
        $response->assertSee('RC-TEST-001');
        $response->assertSee('GH₵ 15.00', false);
        $response->assertSee(route('result-checkers.status'), false);
    }

    public function test_pending_payment_order_shows_the_correct_status_and_no_delivered_step(): void
    {
        $order = ResultCheckerOrder::factory()->create([
            'status' => 'pending_payment',
            'paid_at' => null,
            'fulfilled_at' => null,
        ]);

        $response = $this->get(route('result-checkers.status.show', $order));

        $response->assertOk();
        $response->assertSee('Awaiting Payment');
        $response->assertSee('Please complete the payment to proceed');
        $response->assertSee('Pending…');
    }

    public function test_payment_failure_flash_from_the_gateway_callback_is_surfaced(): void
    {
        // ResultCheckerPaymentCallbackController flashes these two session
        // keys when the gateway can't confirm payment, before redirecting
        // here — the page must surface that, not silently show "Pending".
        $order = ResultCheckerOrder::factory()->create(['status' => 'pending_payment']);

        $response = $this->withSession([
            'payment_failed' => true,
            'payment_message' => 'The gateway declined the transaction.',
        ])->get(route('result-checkers.status.show', $order));

        $response->assertOk();
        $response->assertSee("We couldn't confirm your payment", false);
        $response->assertSee('The gateway declined the transaction.');
    }
}
