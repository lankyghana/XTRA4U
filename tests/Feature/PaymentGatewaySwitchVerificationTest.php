<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Payment reconciliation hardening — Phase 1 regression coverage.
 *
 * These tests defend the two structural guarantees the audit called for:
 *
 *  1. An existing payment is ALWAYS verified against the gateway that
 *     actually created it — never whichever gateway is currently the admin
 *     default. Switching the platform default must have zero effect on a
 *     payment already in flight under a different gateway (CheckoutController
 *     ::verify() -> PaymentService::checkPaymentStatusForGateway() ->
 *     GatewayManager::verifyCollectionWithGateway()).
 *
 *  2. A verification call that itself fails to return an authoritative
 *     reading (connection exception, timeout, HTTP 5xx, malformed response)
 *     must never be treated as a failed payment — the order stays pending,
 *     nothing is fulfilled, no commission/wallet credit happens, and the
 *     reference remains available for a later check. Only an explicit
 *     terminal status from the gateway (failed/declined/cancelled) may mark
 *     the order failed.
 */
class PaymentGatewaySwitchVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(bool $default = true): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    protected function makePayazaConfig(bool $default = false): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ]);
    }

    protected function product(Vendor $vendor, float $price): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    protected function basePayload(Vendor $vendor, Product $product): array
    {
        return [
            'vendor_id' => $vendor->id,
            'category_id' => 'data',
            'service_id' => 'svc_test',
            'package_id' => 'pkg_test',
            'amount' => $product->price,
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 0,
            'original_product_id' => $product->id,
        ];
    }

    // -----------------------------------------------------------------
    // 1. Default-gateway-switch immunity (the exact scenario requested)
    // -----------------------------------------------------------------

    public function test_switching_default_to_payaza_does_not_affect_verification_of_an_existing_paystack_order(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Initialized',
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 50.00);

        // 1 & 2. Customer creates payment REF-A using Paystack (the current default).
        $processResp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product));
        $processResp->assertOk();

        // 3. Order stores payment_gateway=paystack.
        $order = Order::latest('id')->first();
        $this->assertSame(PaymentGatewayConfig::GATEWAY_PAYSTACK, $order->payment_gateway);
        $reference = $order->payment_reference;
        $this->assertNotEmpty($reference);

        // 4. Admin changes default collection gateway to Payaza.
        $payaza->setAsDefault();
        $this->assertSame(
            PaymentGatewayConfig::GATEWAY_PAYAZA,
            PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)->gateway_name
        );

        // Now fake BOTH gateways' verify endpoints. Paystack says success;
        // Payaza is wired to explode if it is ever hit — proving it wasn't.
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verified',
                'data' => ['status' => 'success', 'amount' => 5000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        // 5. REF-A is verified.
        $verifyResp = $this->postJson(route('checkout.verify'), ['reference' => $reference]);

        // 6. XTRA4U MUST query Paystack.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.paystack.co/transaction/verify'));

        // 7. Payaza MUST NOT receive the verification request.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payaza'));

        $verifyResp->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_switching_default_to_paystack_does_not_affect_verification_of_an_existing_payaza_order(): void
    {
        // Inverse of the above: Payaza is default when the order is created.
        $paystack = $this->makePaystackConfig(default: false);
        $this->makePayazaConfig(default: true);

        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 25.00);

        // Payaza's Web Checkout SDK needs no server-to-server "initialize" call —
        // requestPayment() only generates a reference and a checkout_config.
        $processResp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product));
        $processResp->assertOk();

        $order = Order::latest('id')->first();
        $this->assertSame(PaymentGatewayConfig::GATEWAY_PAYAZA, $order->payment_gateway);
        $reference = $order->payment_reference;

        // Admin switches default collection gateway to Paystack.
        $paystack->setAsDefault();

        Http::fake([
            'https://api.payaza.africa/*' => Http::response([
                'response_message' => 'ok',
                'transaction_status' => 'successful',
                'transaction_amount' => 25.00,
                'transaction_reference' => $reference,
            ], 200),
            'https://api.paystack.co/*' => Http::response('should never be called', 500),
        ]);

        $verifyResp = $this->postJson(route('checkout.verify'), ['reference' => $reference]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'payaza.africa'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'paystack'));

        $verifyResp->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // 2. Network-failure semantics: uncertainty must stay pending
    // -----------------------------------------------------------------

    public function test_connection_exception_during_verification_leaves_order_pending_not_failed(): void
    {
        $this->makePaystackConfig();

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 30.00);
        $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product))->assertOk();
        $order = Order::latest('id')->first();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $resp = $this->postJson(route('checkout.verify'), ['reference' => $order->payment_reference]);

        $resp->assertOk()->assertJson(['status' => 'pending']);

        $order->refresh();
        $this->assertNotSame('failed', $order->payment_status);
        $this->assertNotSame('Failed', $order->status);
        // Never silently fulfilled either.
        $this->assertNotSame('paid', $order->payment_status);
        // Reference is untouched and still available for a later check.
        $this->assertNotEmpty($order->payment_reference);

        // No vendor wallet credit happened.
        $vendor->refresh();
        $this->assertEquals(0, (float) $vendor->wallet_balance);
    }

    public function test_http_500_during_verification_leaves_order_pending_not_failed(): void
    {
        $this->makePaystackConfig();

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 30.00);
        $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product))->assertOk();
        $order = Order::latest('id')->first();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response('Internal Server Error', 500),
        ]);

        $resp = $this->postJson(route('checkout.verify'), ['reference' => $order->payment_reference]);

        $resp->assertOk()->assertJson(['status' => 'pending']);
        $this->assertNotSame('failed', $order->fresh()->payment_status);
    }

    public function test_malformed_response_during_verification_leaves_order_pending_not_failed(): void
    {
        $this->makePaystackConfig();

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 30.00);
        $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product))->assertOk();
        $order = Order::latest('id')->first();

        // Not valid JSON at all.
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response('<html>not json</html>', 200),
        ]);

        $resp = $this->postJson(route('checkout.verify'), ['reference' => $order->payment_reference]);

        $resp->assertOk()->assertJson(['status' => 'pending']);
        $this->assertNotSame('failed', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // 3. Authoritative failure IS still respected (this must not regress)
    // -----------------------------------------------------------------

    public function test_gateway_confirmed_decline_marks_order_failed(): void
    {
        $this->makePaystackConfig();

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 30.00);
        $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product))->assertOk();
        $order = Order::latest('id')->first();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'amount' => 3000],
            ], 200),
        ]);

        $resp = $this->postJson(route('checkout.verify'), ['reference' => $order->payment_reference]);

        $resp->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }
}
