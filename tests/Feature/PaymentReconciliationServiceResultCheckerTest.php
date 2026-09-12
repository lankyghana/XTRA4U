<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\ResultCheckerOrder;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationServiceResultCheckerTest extends TestCase
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

    protected function pendingOrder(string $reference, float $price = 100.00, string $gateway = 'paystack'): ResultCheckerOrder
    {
        return ResultCheckerOrder::factory()->create([
            'payment_reference' => $reference,
            'payment_gateway' => $gateway,
            'status' => 'pending_payment',
            'total_price' => $price,
            'unit_price' => $price,
        ]);
    }

    protected function service(): PaymentReconciliationService
    {
        return app(PaymentReconciliationService::class);
    }

    // A. SUCCESS
    public function test_success_completes_exactly_once(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-A', 100.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 10000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    // B. FAILED
    public function test_authoritative_failure_marks_failed(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-B', 100.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 10000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame('failed', $order->fresh()->status);
    }

    // C. PENDING
    public function test_provider_pending_stays_pending(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-C', 100.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    // D. UNKNOWN / network error
    public function test_network_error_stays_unresolved(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-D', 100.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    // E. ORIGINAL GATEWAY after default changes
    public function test_original_gateway_is_queried_after_default_changes(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);
        $order = $this->pendingOrder('RC-E', 100.00, 'paystack');

        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 10000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $outcome = $this->service()->reconcile($order);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payaza'));
        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
    }

    // F. DUPLICATE RECONCILIATION
    public function test_duplicate_reconciliation_allocates_pins_exactly_once(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-F', 100.00);
        \App\Models\ResultCheckerPin::factory()->count(3)->create([
            'service_id' => $order->service_id,
            'status' => 'available',
        ]);
        $order->update(['quantity' => 1]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 10000],
            ], 200),
        ]);

        $this->service()->reconcile($order);
        $this->service()->reconcile($order->fresh());

        $this->assertEquals(1, $order->fresh()->pins()->count(), 'Duplicate reconciliation must not allocate extra pins.');
    }

    // G. WEBHOOK RACE
    public function test_webhook_completing_first_is_not_clobbered(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-G', 100.00);
        \App\Models\ResultCheckerPin::factory()->count(3)->create([
            'service_id' => $order->service_id,
            'status' => 'available',
        ]);
        $order->update(['quantity' => 1]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 10000],
            ], 200),
        ]);

        app(\App\Services\ResultCheckerService::class)->handlePaymentCallback($order->fresh(), 'RC-G', 'paystack');
        $this->assertSame('completed', $order->fresh()->status);

        $this->service()->reconcile($order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertEquals(1, $order->fresh()->pins()->count());
    }

    // H. INTEGRITY MISMATCH
    public function test_success_with_wrong_amount_does_not_fulfil(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-H', 100.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 500],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_INTEGRITY_MISMATCH, $outcome);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_no_recorded_gateway_is_parked_without_any_gateway_call(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('RC-NO-GW', 100.00);
        DB::table('result_checker_orders')->where('id', $order->id)->update(['payment_gateway' => null]);

        Http::fake();

        $outcome = $this->service()->reconcile($order->fresh());

        Http::assertNothingSent();
        $this->assertSame(PaymentReconciliationService::OUTCOME_NO_GATEWAY, $outcome);
    }
}
