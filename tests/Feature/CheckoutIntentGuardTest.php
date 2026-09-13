<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4 — direct unit-level coverage of CheckoutIntentGuard, the shared
 * mechanism every initiation endpoint (Order, AfaRegistration,
 * ResultCheckerOrder, UssdSubscription, WalletTopup) consults before
 * creating a new payable row. Surface-specific HTTP-level coverage lives in
 * the DuplicateChargePrevention*Test files; this file exercises the guard's
 * own semantics in isolation, using Order as the vehicle since it is the
 * simplest payable to construct.
 */
class CheckoutIntentGuardTest extends TestCase
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

    protected function order(array $attrs = []): Order
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => 20.00,
            'is_active' => true,
        ]);

        return Order::create(array_merge([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
        ], $attrs));
    }

    protected function guard(): CheckoutIntentGuard
    {
        return app(CheckoutIntentGuard::class);
    }

    private function isSuccess(Order $o): bool
    {
        return in_array($o->payment_status, ['paid', 'completed'], true);
    }

    private function isFailed(Order $o): bool
    {
        return $o->payment_status === 'failed';
    }

    private function hasGateway(Order $o): bool
    {
        return (bool) $o->payment_gateway && (bool) $o->payment_reference;
    }

    public function test_evaluate_proceeds_when_no_key_supplied(): void
    {
        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', null,
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::PROCEED, $decision['action']);
        $this->assertNull($decision['payable']);
    }

    public function test_evaluate_proceeds_when_key_has_no_matching_row(): void
    {
        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', 'never-seen-key',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::PROCEED, $decision['action']);
    }

    public function test_cross_scope_key_reuse_never_returns_another_owners_row(): void
    {
        // Security: an attacker who learns/guesses another customer's key
        // must never be able to reuse or reference their row from a
        // different scope (session/vendor identity).
        $victimOrder = $this->order(['idempotency_scope' => 'session:victim', 'idempotency_key' => 'shared-key']);

        $decision = $this->guard()->evaluate(
            Order::class, 'session:attacker', 'shared-key',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::PROCEED, $decision['action']);
        $this->assertNull($decision['payable']);
        $this->assertNotEquals($victimOrder->id, $decision['payable']?->id);
    }

    public function test_already_succeeded_short_circuits_without_any_gateway_call(): void
    {
        $this->makePaystackConfig();
        $order = $this->order([
            'payment_status' => 'paid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-DONE',
            'idempotency_scope' => 'session:abc',
            'idempotency_key' => 'key-1',
        ]);

        Http::fake(); // any call at all fails via assertNothingSent below

        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', 'key-1',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        Http::assertNothingSent();
        $this->assertSame(CheckoutIntentGuard::ALREADY_SUCCEEDED, $decision['action']);
        $this->assertSame($order->id, $decision['payable']->id);
    }

    public function test_terminally_failed_row_returns_retry_after_failure(): void
    {
        $this->order([
            'payment_status' => 'failed',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-FAILED',
            'idempotency_scope' => 'session:abc',
            'idempotency_key' => 'key-2',
        ]);

        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', 'key-2',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::RETRY_AFTER_FAILURE, $decision['action']);
    }

    public function test_pending_row_is_reverified_and_resolves_to_already_succeeded(): void
    {
        $this->makePaystackConfig();
        $order = $this->order([
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-PEND-OK',
            'idempotency_scope' => 'session:abc',
            'idempotency_key' => 'key-3',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', 'key-3',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::ALREADY_SUCCEEDED, $decision['action']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_pending_row_stays_pending_when_gateway_confirms_nothing_new(): void
    {
        $this->makePaystackConfig();
        $this->order([
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-PEND-STILL',
            'idempotency_scope' => 'session:abc',
            'idempotency_key' => 'key-4',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', 'key-4',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::STILL_PENDING, $decision['action']);
    }

    public function test_pending_row_stays_pending_on_network_error_never_treated_as_failed(): void
    {
        $this->makePaystackConfig();
        $this->order([
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-PEND-UNKNOWN',
            'idempotency_scope' => 'session:abc',
            'idempotency_key' => 'key-5',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timed out');
            },
        ]);

        $decision = $this->guard()->evaluate(
            Order::class, 'session:abc', 'key-5',
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::STILL_PENDING, $decision['action']);
    }

    public function test_create_or_reuse_detects_a_concurrent_duplicate_insert(): void
    {
        // Simulates the concurrency case: by the time this request's INSERT
        // runs, another request (double-click/duplicate POST/two tabs) has
        // already committed a row for the identical (scope, key) pair.
        $vendor = Vendor::factory()->create();
        $winner = Order::create([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => 'MTN 1GB',
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'idempotency_scope' => 'session:race',
            'idempotency_key' => 'race-key',
        ]);

        $result = $this->guard()->createOrReuse(
            Order::class,
            'session:race',
            'race-key',
            fn (?string $key) => Order::create([
                'recipient_phone_number' => '0244000000',
                'mobile_money_number' => '0244000000',
                'service_purchased' => 'MTN 1GB',
                'amount_paid' => 20.00,
                'vendor_id' => $vendor->id,
                'status' => 'Pending',
                'payment_status' => 'unpaid',
                'idempotency_scope' => 'session:race',
                'idempotency_key' => $key,
            ]),
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        // Exactly one row for this intent — the loser never created its own.
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($winner->id, $result['payable']->id);
        $this->assertSame(CheckoutIntentGuard::STILL_PENDING, $result['action']);
    }

    public function test_create_or_reuse_retries_with_a_fresh_key_when_collided_row_is_failed(): void
    {
        $vendor = Vendor::factory()->create();
        Order::create([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => 'MTN 1GB',
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'status' => 'Failed',
            'payment_status' => 'failed',
            'idempotency_scope' => 'session:race2',
            'idempotency_key' => 'race-key-2',
        ]);

        $result = $this->guard()->createOrReuse(
            Order::class,
            'session:race2',
            'race-key-2',
            function (?string $key) use ($vendor) {
                // Force the very first attempt (with the ORIGINAL key) to
                // collide, exactly like the concurrent-insert test above;
                // the guard must retry once with freshKeyAfterFailure().
                if ($key === 'race-key-2') {
                    return Order::create([
                        'recipient_phone_number' => '0244000000',
                        'mobile_money_number' => '0244000000',
                        'service_purchased' => 'MTN 1GB',
                        'amount_paid' => 20.00,
                        'vendor_id' => $vendor->id,
                        'status' => 'Pending',
                        'payment_status' => 'unpaid',
                        'idempotency_scope' => 'session:race2',
                        'idempotency_key' => $key,
                    ]);
                }

                return Order::create([
                    'recipient_phone_number' => '0244000000',
                    'mobile_money_number' => '0244000000',
                    'service_purchased' => 'MTN 1GB',
                    'amount_paid' => 20.00,
                    'vendor_id' => $vendor->id,
                    'status' => 'Pending',
                    'payment_status' => 'unpaid',
                    'idempotency_scope' => 'session:race2',
                    'idempotency_key' => $key,
                ]);
            },
            fn ($o) => $this->isSuccess($o), fn ($o) => $this->isFailed($o), fn ($o) => $this->hasGateway($o),
        );

        $this->assertSame(CheckoutIntentGuard::PROCEED, $result['action']);
        $this->assertNotSame('race-key-2', $result['payable']->idempotency_key);
        $this->assertStringStartsWith('race-key-2:retry-', $result['payable']->idempotency_key);
        // The failed row plus the one genuinely new row — never mutated back to pending.
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseHas('orders', ['idempotency_key' => 'race-key-2', 'payment_status' => 'failed']);
    }

    public function test_fresh_key_after_failure_is_deterministically_distinct(): void
    {
        $a = $this->guard()->freshKeyAfterFailure('abc');
        $b = $this->guard()->freshKeyAfterFailure('abc');

        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('abc:retry-', $a);
    }
}
