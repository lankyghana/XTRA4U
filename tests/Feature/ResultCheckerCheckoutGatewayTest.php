<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResultCheckerCheckoutGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private NetworkService $service;
    private VendorResultCheckerSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();

        $this->service = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'base_price'   => 50.00,
            'is_active'    => true,
        ]);

        $this->setting = VendorResultCheckerSetting::create([
            'vendor_id'     => $this->vendor->id,
            'service_id'    => $this->service->id,
            'profit_amount' => 10.00,
            'is_active'     => true,
        ]);

        PaymentGatewayConfig::create([
            'gateway_name'        => 'paystack',
            'gateway_type'        => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic'    => true,
            'supports_payout'     => false,
            'supports_sms'        => false,
            'supports_webhook'    => true,
            'is_active'           => true,
            'is_default'          => true,
            'environment'         => 'sandbox',
            'config_data'         => [
                'public_key'  => 'pk_test_123',
                'secret_key'  => 'sk_test_abc',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    // -------------------------------------------------------------------------
    // initiateCheckout
    // -------------------------------------------------------------------------

    public function test_checkout_initiates_and_returns_authorization_url(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status'  => true,
                'message' => 'Authorization URL created',
                'data'    => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code'       => 'abc123',
                    'reference'         => 'XTRA4U-RC-TEST-1',
                ],
            ], 200),
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'      => $this->service->id,
                'quantity'        => 1,
                'customer_phone'  => '0241234567',
                'customer_name'   => 'Test Customer',
            ]
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('payment_data.gateway', 'paystack')
            ->assertJsonPath('payment_data.collection_flow', 'redirect');

        $this->assertDatabaseHas('result_checker_orders', [
            'vendor_id'      => $this->vendor->id,
            'service_id'     => $this->service->id,
            'quantity'       => 1,
            'unit_price'     => 60.00,
            'total_price'    => 60.00,
            'vendor_profit'  => 10.00,
            'status'         => 'pending_payment',
        ]);

        $this->assertNotNull(
            ResultCheckerOrder::where('vendor_id', $this->vendor->id)->first()?->payment_reference
        );
    }

    public function test_checkout_calculates_price_for_multiple_quantity(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data'   => ['authorization_url' => 'https://checkout.paystack.com/xyz'],
            ], 200),
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $this->service->id,
                'quantity'       => 3,
                'customer_phone' => '0241234567',
            ]
        );

        $this->assertDatabaseHas('result_checker_orders', [
            'quantity'      => 3,
            'unit_price'    => 60.00,
            'total_price'   => 180.00,
            'vendor_profit' => 30.00,
        ]);
    }

    public function test_checkout_returns_404_for_non_result_checker_service(): void
    {
        $dataService = NetworkService::factory()->create([
            'service_type' => 'data',
            'is_active'    => true,
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $dataService->id,
                'quantity'       => 1,
                'customer_phone' => '0241234567',
            ]
        );

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_checkout_returns_404_for_inactive_service(): void
    {
        $inactive = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'is_active'    => false,
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $inactive->id,
                'quantity'       => 1,
                'customer_phone' => '0241234567',
            ]
        );

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_checkout_returns_403_when_vendor_does_not_offer_service(): void
    {
        $otherService = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'is_active'    => true,
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $otherService->id,
                'quantity'       => 1,
                'customer_phone' => '0241234567',
            ]
        );

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_checkout_returns_403_when_vendor_setting_is_inactive(): void
    {
        $this->setting->update(['is_active' => false]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $this->service->id,
                'quantity'       => 1,
                'customer_phone' => '0241234567',
            ]
        );

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_checkout_returns_400_when_gateway_fails(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status'  => false,
                'message' => 'Invalid key',
            ], 400),
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $this->service->id,
                'quantity'       => 1,
                'customer_phone' => '0241234567',
            ]
        );

        $response->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('result_checker_orders', ['status' => 'failed']);
    }

    public function test_checkout_stores_payer_phone_in_metadata(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data'   => ['authorization_url' => 'https://checkout.paystack.com/abc'],
            ], 200),
        ]);

        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id'     => $this->service->id,
                'quantity'       => 1,
                'customer_phone' => '0241234567',
                'payer_phone'    => '0551112233',
                'payer_network'  => 'MTN',
            ]
        );

        $response->assertStatus(200);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'No HTTP requests were recorded');

        $initRequest = collect($recorded)
            ->first(fn ($pair) => str_contains((string) $pair[0]->url(), '/transaction/initialize'));

        $this->assertNotNull($initRequest, 'Initialize request was not sent');

        $body = $initRequest[0]->data();
        $this->assertEquals('0551112233', $body['metadata']['payer_phone'] ?? null);
        $this->assertEquals('MTN', $body['metadata']['payer_network'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Payment callback (redirect flow)
    // -------------------------------------------------------------------------

    public function test_callback_completes_order_and_redirects_to_success(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-CALLBACK-TEST',
            'payment_gateway'   => 'paystack',
        ]);

        ResultCheckerPin::factory()->create([
            'service_id' => $this->service->id,
            'status'     => 'available',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => [
                    'status' => 'success',
                    'amount' => 6000,
                ],
            ], 200),
        ]);

        $response = $this->get(
            route('result-checkers.payment.callback', ['order' => $order->id])
            . '?reference=XTRA4U-RC-CALLBACK-TEST'
        );

        $response->assertRedirect(route('result-checkers.success', $order));

        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'completed',
        ]);
    }

    public function test_callback_redirects_to_pending_stock_when_no_pins_available(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-PENDING-TEST',
            'payment_gateway'   => 'paystack',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $response = $this->get(
            route('result-checkers.payment.callback', ['order' => $order->id])
            . '?reference=XTRA4U-RC-PENDING-TEST'
        );

        $response->assertRedirect(route('result-checkers.pending-stock', $order));

        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'pending_stock',
        ]);
    }

    public function test_callback_marks_order_failed_when_payment_not_successful(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-FAILED-TEST',
            'payment_gateway'   => 'paystack',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'failed', 'amount' => 6000],
            ], 200),
        ]);

        $this->get(
            route('result-checkers.payment.callback', ['order' => $order->id])
            . '?reference=XTRA4U-RC-FAILED-TEST'
        );

        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'failed',
        ]);
    }

    public function test_callback_returns_400_when_no_reference(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'      => $this->vendor->id,
            'service_id'     => $this->service->id,
            'customer_phone' => '0241234567',
            'quantity'       => 1,
            'unit_price'     => 60.00,
            'total_price'    => 60.00,
            'vendor_profit'  => 10.00,
            'status'         => 'pending_payment',
        ]);

        $response = $this->getJson(
            route('result-checkers.payment.callback', ['order' => $order->id])
        );

        $response->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'Missing payment reference']);
    }

    public function test_callback_is_idempotent_for_already_completed_order(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'completed',
            'paid_at'           => now(),
            'payment_reference' => 'XTRA4U-RC-IDEM-TEST',
            'payment_gateway'   => 'paystack',
        ]);

        ResultCheckerPin::factory()->create([
            'service_id'              => $this->service->id,
            'result_checker_order_id' => $order->id,
            'status'                  => 'sold',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $response = $this->get(
            route('result-checkers.payment.callback', ['order' => $order->id])
            . '?reference=XTRA4U-RC-IDEM-TEST'
        );

        $response->assertRedirect(route('result-checkers.success', $order));

        $this->assertEquals(1, $order->pins()->count(), 'Pins must not be duplicated on repeat callback');
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    public function test_webhook_completes_order_on_successful_payment(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-WH-TEST',
            'payment_gateway'   => 'paystack',
        ]);

        ResultCheckerPin::factory()->create([
            'service_id' => $this->service->id,
            'status'     => 'available',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $response = $this->postJson(
            route('result-checkers.payment.webhook'),
            ['reference' => 'XTRA4U-RC-WH-TEST']
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'completed',
        ]);
    }

    public function test_webhook_sets_pending_stock_when_no_pins_available(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-WH-NOSTOCK',
            'payment_gateway'   => 'paystack',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $response = $this->postJson(
            route('result-checkers.payment.webhook'),
            ['reference' => 'XTRA4U-RC-WH-NOSTOCK']
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'pending_stock',
        ]);
    }

    public function test_webhook_returns_404_when_order_not_found(): void
    {
        $response = $this->postJson(
            route('result-checkers.payment.webhook'),
            ['reference' => 'XTRA4U-RC-UNKNOWN-REF']
        );

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_webhook_returns_400_when_missing_reference(): void
    {
        $response = $this->postJson(
            route('result-checkers.payment.webhook'),
            []
        );

        $response->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'Missing payment reference']);
    }

    public function test_webhook_returns_400_when_payment_status_is_failed(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-WH-FAIL',
            'payment_gateway'   => 'paystack',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'failed', 'amount' => 6000],
            ], 200),
        ]);

        $response = $this->postJson(
            route('result-checkers.payment.webhook'),
            ['reference' => 'XTRA4U-RC-WH-FAIL']
        );

        $response->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'failed',
        ]);
    }

    public function test_webhook_is_idempotent_on_duplicate_call(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id'         => $this->vendor->id,
            'service_id'        => $this->service->id,
            'customer_phone'    => '0241234567',
            'quantity'          => 1,
            'unit_price'        => 60.00,
            'total_price'       => 60.00,
            'vendor_profit'     => 10.00,
            'status'            => 'completed',
            'paid_at'           => now(),
            'payment_reference' => 'XTRA4U-RC-WH-IDEM',
            'payment_gateway'   => 'paystack',
        ]);

        ResultCheckerPin::factory()->create([
            'service_id'              => $this->service->id,
            'result_checker_order_id' => $order->id,
            'status'                  => 'sold',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data'   => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $this->postJson(
            route('result-checkers.payment.webhook'),
            ['reference' => 'XTRA4U-RC-WH-IDEM']
        );

        $this->assertEquals(1, $order->pins()->count(), 'Duplicate webhook must not allocate extra pins');
        $this->assertDatabaseHas('result_checker_orders', [
            'id'     => $order->id,
            'status' => 'completed',
        ]);
    }
}
