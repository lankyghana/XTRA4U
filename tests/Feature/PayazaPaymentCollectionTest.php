<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Services\PayazaPaymentService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayazaPaymentCollectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Give a fixture order the immutable financial terms every creation path
     * now freezes at creation. Derived from the order's own amount so a test
     * that overrides `amount_paid` never ends up with an order whose expected
     * amount contradicts it.
     */
    protected static function withPricingSnapshot(array $attributes): array
    {
        return array_merge([
            'expected_amount' => $attributes['amount_paid'] ?? null,
            'currency' => 'GHS',
            'pricing_snapshot_at' => now(),
        ], $attributes);
    }

    protected function makeConfig(array $overrides = []): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create(array_merge([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ], $overrides));
    }

    protected function makeOrder(Vendor $vendor, array $overrides = []): Order
    {
        return Order::create(self::withPricingSnapshot(array_merge([
            'recipient_phone_number' => '0240000001',
            'mobile_money_number' => '0244123456',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 5.50,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ], $overrides)));
    }

    // 1. Payaza gateway registration
    public function test_payaza_is_registered_and_resolvable_via_gateway_manager(): void
    {
        $this->makeConfig();

        $gatewayManager = new \App\Services\GatewayManager;
        $service = $gatewayManager->getPaymentServiceByGateway(PaymentGatewayConfig::GATEWAY_PAYAZA);

        $this->assertInstanceOf(PayazaPaymentService::class, $service);
    }

    // 2. Payaza disabled
    public function test_payaza_disabled_gateway_blocks_initiation(): void
    {
        $this->makeConfig(['is_active' => false, 'is_default' => false]);

        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor);

        $init = (new PaymentService)->initiatePayment($order, 'vendor@example.com', 5.50);

        $this->assertFalse($init['success']);
        $this->assertSame('No active payment gateway configured.', $init['message']);
    }

    // 3. Test-mode initialization uses connection_mode = Test
    public function test_sandbox_environment_maps_to_test_connection_mode(): void
    {
        $this->makeConfig(['environment' => PaymentGatewayConfig::ENV_SANDBOX]);

        $vendor = Vendor::factory()->create(['name' => 'Jane Doe']);
        $order = $this->makeOrder($vendor);

        $init = (new PaymentService)->initiatePayment($order, 'vendor@example.com', 5.50);

        $this->assertTrue($init['success']);
        $this->assertSame('payaza', $init['gateway_name']);
        $this->assertSame('payaza', $init['flow_type']);
        $this->assertSame('Test', $init['checkout_config']['connection_mode']);
    }

    public function test_live_environment_maps_to_live_connection_mode(): void
    {
        $this->makeConfig(['environment' => PaymentGatewayConfig::ENV_LIVE]);

        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor);

        $init = (new PaymentService)->initiatePayment($order, 'vendor@example.com', 5.50);

        $this->assertSame('Live', $init['checkout_config']['connection_mode']);
    }

    // 4. Unique transaction reference
    public function test_each_payment_attempt_gets_a_unique_reference(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();

        $orderA = $this->makeOrder($vendor);
        $orderB = $this->makeOrder($vendor);

        $initA = (new PaymentService)->initiatePayment($orderA, 'vendor@example.com', 5.50);
        $initB = (new PaymentService)->initiatePayment($orderB, 'vendor@example.com', 5.50);

        $this->assertNotEmpty($initA['reference']);
        $this->assertNotEmpty($initB['reference']);
        $this->assertNotSame($initA['reference'], $initB['reference']);
        $this->assertSame($initA['reference'], $orderA->fresh()->payment_reference);
    }

    // 5. Correct GHS amount + currency, and public key never leaks the secret
    public function test_checkout_config_carries_correct_amount_currency_and_no_secret(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, ['amount_paid' => 12.34]);

        $init = (new PaymentService)->initiatePayment($order, 'vendor@example.com', 12.34);

        $config = $init['checkout_config'];
        $this->assertSame(12.34, $config['checkout_amount']);
        $this->assertSame('GHS', $config['currency_code']);
        $this->assertSame('test-public-key', $config['merchant_key']);
        $this->assertSame($init['reference'], $config['transaction_reference']);
        $this->assertArrayNotHasKey('secret_key', $config);
        $this->assertStringNotContainsString('test-secret-key', json_encode($config));
    }

    // Invalid phone blocks initiation before any network call
    public function test_invalid_payer_phone_blocks_initiation(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, ['mobile_money_number' => '123', 'recipient_phone_number' => '123']);

        $init = (new PaymentService)->initiatePayment($order, 'vendor@example.com', 5.50);

        $this->assertFalse($init['success']);
        $this->assertStringContainsString('Ghana phone number', $init['message']);
    }

    // 6/8. Successful checkout verification / pending payment
    public function test_verify_payment_maps_completed_status_to_success(): void
    {
        $config = $this->makeConfig();

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_code' => '00',
                'transaction_reference' => 'XTRA4U-PYZ-ABC123-1',
                'transaction_amount' => 5.50,
                'transaction_status' => 'Completed',
                'currency' => 'GHS',
            ], 200),
        ]);

        $result = (new PayazaPaymentService($config))->verifyPayment('XTRA4U-PYZ-ABC123-1');

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['data']['status']);
        $this->assertSame(5.50, $result['data']['amount']);
    }

    public function test_verify_payment_maps_initialized_status_to_pending(): void
    {
        $config = $this->makeConfig();

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_code' => '09',
                'transaction_reference' => 'XTRA4U-PYZ-ABC123-1',
                'transaction_status' => 'Initialized',
            ], 200),
        ]);

        $result = (new PayazaPaymentService($config))->verifyPayment('XTRA4U-PYZ-ABC123-1');

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['data']['status']);
    }

    // 7. Failed payment
    public function test_verify_payment_maps_failure_response_code_to_failed(): void
    {
        $config = $this->makeConfig();

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_code' => '06',
                'transaction_reference' => 'XTRA4U-PYZ-ABC123-1',
                'transaction_status' => 'Failed',
            ], 200),
        ]);

        $result = (new PayazaPaymentService($config))->verifyPayment('XTRA4U-PYZ-ABC123-1');

        $this->assertTrue($result['success']);
        $this->assertSame('failed', $result['data']['status']);
    }

    // 9. Invalid/unknown transaction reference
    public function test_verify_payment_handles_not_found_response(): void
    {
        $config = $this->makeConfig();

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_message' => 'Transaction not found',
            ], 400),
        ]);

        $result = (new PayazaPaymentService($config))->verifyPayment('does-not-exist');

        $this->assertFalse($result['success']);
    }

    // 20. Network/service timeout
    public function test_verify_payment_handles_connection_exception_gracefully(): void
    {
        $config = $this->makeConfig();

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $result = (new PayazaPaymentService($config))->verifyPayment('XTRA4U-PYZ-ABC123-1');

        $this->assertFalse($result['success']);
    }

    // isConfigured() must not require secret_key (webhook-only credential)
    public function test_gateway_is_configured_without_secret_key(): void
    {
        $config = $this->makeConfig([
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => '',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->assertTrue((new PayazaPaymentService($config))->isConfigured());
    }

    public function test_gateway_is_not_configured_without_public_key(): void
    {
        $config = $this->makeConfig([
            'config_data' => [
                'public_key' => '',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->assertFalse((new PayazaPaymentService($config))->isConfigured());
    }

    // Amount mismatch guard on the /checkout/verify endpoint (the path the
    // Payaza SDK popup's callback/onClose actually call). Payaza's Web
    // Checkout SDK sets the charge amount client-side, so a tampered popup
    // that genuinely pays less than the order's expected amount must not
    // be waved through into fulfillment.
    public function test_checkout_verify_endpoint_refuses_amount_mismatch(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, [
            'amount_paid' => 10.00,
            'payment_reference' => 'XTRA4U-PYZ-VERIFYAMT-1',
        ]);

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_code' => '00',
                'transaction_reference' => 'XTRA4U-PYZ-VERIFYAMT-1',
                'transaction_amount' => 0.50,
                'transaction_status' => 'Completed',
            ], 200),
        ]);

        $response = $this->postJson(route('checkout.verify'), ['reference' => 'XTRA4U-PYZ-VERIFYAMT-1']);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'failed']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_checkout_verify_endpoint_completes_order_when_amount_matches(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, [
            'amount_paid' => 10.00,
            'payment_reference' => 'XTRA4U-PYZ-VERIFYOK-1',
        ]);

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_code' => '00',
                'transaction_reference' => 'XTRA4U-PYZ-VERIFYOK-1',
                'transaction_amount' => 10.00,
                'transaction_status' => 'Completed',
            ], 200),
        ]);

        $response = $this->postJson(route('checkout.verify'), ['reference' => 'XTRA4U-PYZ-VERIFYOK-1']);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'success']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // 18. Existing default gateway (e.g. Paystack) is untouched by Payaza's presence
    public function test_paystack_still_works_when_payaza_also_configured_but_not_default(): void
    {
        $this->makeConfig(['is_default' => false]);

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_abc',
                'payment_url' => 'https://api.paystack.co',
            ],
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, ['payment_gateway' => 'paystack']);

        $init = (new PaymentService)->initiatePayment($order, 'vendor@example.com', 5.50);

        $this->assertTrue($init['success']);
        $this->assertSame('paystack', $init['gateway_name']);
        $this->assertNotEmpty($init['authorization_url']);
    }
}
