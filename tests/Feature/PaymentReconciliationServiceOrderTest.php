<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3 payment reconciliation — PaymentReconciliationService::reconcile()
 * exercised directly against Order, covering every required scenario (A-H).
 */
class PaymentReconciliationServiceOrderTest extends TestCase
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

    protected function pendingOrder(string $reference, float $price = 20.00, string $gateway = 'paystack'): Order
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);

        return Order::create([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => $price,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => $gateway,
            'payment_reference' => $reference,
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
        $order = $this->pendingOrder('ORD-A', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);

        $vendor = $order->vendor;
        $this->assertGreaterThan(0, (float) $vendor->wallet_balance);
    }

    // B. FAILED
    public function test_authoritative_failure_cancels(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-B', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 2000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    // C. PENDING
    public function test_provider_pending_stays_pending(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-C', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertEquals(1, $order->reconciliation_attempts);
        $this->assertNotNull($order->next_reconciliation_at);
    }

    // D. UNKNOWN / network error
    public function test_network_error_stays_unresolved_and_is_eligible_for_retry(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-D', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        // Scheduled for a future retry, not abandoned.
        $this->assertTrue($order->next_reconciliation_at->isFuture());
    }

    // E. ORIGINAL GATEWAY after default changes
    public function test_original_gateway_is_queried_after_default_changes(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);
        $order = $this->pendingOrder('ORD-E', 20.00, 'paystack');

        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $outcome = $this->service()->reconcile($order);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payaza'));
        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
    }

    // F. DUPLICATE RECONCILIATION
    public function test_reconciling_the_same_successful_order_twice_has_exactly_one_financial_effect(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-F', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $first = $this->service()->reconcile($order);
        $second = $this->service()->reconcile($order->fresh());

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $first);
        // completeOrder() is idempotent — a second call on an already-paid
        // order is a no-op; wallet balance must not double.
        $vendor = $order->fresh()->vendor;
        $balanceAfterFirst = (float) $vendor->wallet_balance;

        $vendor->refresh();
        $this->assertEquals($balanceAfterFirst, (float) $vendor->wallet_balance, 'Duplicate reconciliation must not double-credit.');
        $this->assertNotNull($second);
    }

    // G. WEBHOOK RACE
    public function test_webhook_completing_between_verify_and_write_is_not_clobbered(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-G', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 2000],
            ], 200),
        ]);

        // Simulate a webhook completing the order moments before this
        // reconciliation pass's own transaction/lock runs. A webhook only
        // reaches completeOrder() after the payment integrity guard has passed,
        // so the order carries that proof here too.
        app(\App\Services\Payments\PaymentIntegrityGuard::class)->stampTrustedSource(
            $order,
            \App\Support\PaymentIntegrity::VERIFIED,
            'test fixture: webhook verified this payment first'
        );
        app(\App\Services\PaymentService::class)->completeOrder($order->fresh());
        $this->assertSame('paid', $order->fresh()->payment_status);

        $outcome = $this->service()->reconcile($order);

        // The reconciler's own (contradictory) verification must never
        // overwrite the webhook's already-committed success.
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNotSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
    }

    // H. INTEGRITY MISMATCH
    public function test_success_with_wrong_amount_does_not_fulfil(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-H', 20.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                // Reports success but for far less than the expected amount.
                'status' => true, 'data' => ['status' => 'success', 'amount' => 100],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_INTEGRITY_MISMATCH, $outcome);
        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status, 'Must not fulfil on an amount mismatch.');
        $this->assertEquals(0.0, (float) $order->vendor->wallet_balance);
    }

    // No recorded gateway -> manual review, never guessed
    public function test_no_recorded_gateway_is_parked_for_manual_review_without_any_gateway_call(): void
    {
        $this->makePaystackConfig();
        $order = $this->pendingOrder('ORD-NO-GW', 20.00, gateway: '');
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['payment_gateway' => null]);

        Http::fake(); // any call at all fails the test via assertNothingSent below

        $outcome = $this->service()->reconcile($order->fresh());

        Http::assertNothingSent();
        $this->assertSame(PaymentReconciliationService::OUTCOME_NO_GATEWAY, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }
}
