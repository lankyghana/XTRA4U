<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\ResultCheckerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Same coverage as PaymentGatewaySwitchVerificationTest, for the Result
 * Checker purchase surface — "repeat equivalent coverage where practical for
 * other payable types" per the reconciliation hardening task.
 */
class ResultCheckerGatewaySwitchVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(bool $default): PaymentGatewayConfig
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

    protected function makePayazaConfig(bool $default): PaymentGatewayConfig
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

    public function test_switching_default_gateway_does_not_affect_verification_of_an_existing_result_checker_order(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);

        // Order was created while Paystack was default.
        $order = ResultCheckerOrder::factory()->create([
            'payment_reference' => 'RC-REF-A',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'status' => 'pending_payment',
            'total_price' => 100.00,
        ]);

        // Admin switches the platform default to Payaza.
        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 10000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $resp = $this->postJson(
            route('result-checkers.payment.callback', ['order' => $order->id]),
            ['reference' => 'RC-REF-A'],
            ['Accept' => 'application/json']
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payaza'));

        $resp->assertOk()->assertJson(['status' => 'success']);
    }

    public function test_verification_network_failure_leaves_result_checker_order_pending(): void
    {
        $this->makePaystackConfig(default: true);

        $order = ResultCheckerOrder::factory()->create([
            'payment_reference' => 'RC-REF-B',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'status' => 'pending_payment',
            'total_price' => 100.00,
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $resp = $this->postJson(
            route('result-checkers.payment.callback', ['order' => $order->id]),
            ['reference' => 'RC-REF-B'],
            ['Accept' => 'application/json']
        );

        $resp->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }
}
