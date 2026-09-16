<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — Order surface (both live initiation entry points:
 * CheckoutController::process() behind `checkout.process`, and
 * PurchaseController::store() behind `purchase`, which is what the actual
 * marketplace checkout page's form posts to).
 *
 * A real browser's AJAX retries all share the SAME session cookie
 * automatically; Laravel's test HTTP client does not carry cookies between
 * separate postJson() calls unless told to, so useGuestSession() pins a
 * fixed session cookie for the rest of the test — simulating exactly what a
 * customer's browser does across a double-click/refresh/retry.
 */
class DuplicateChargePreventionOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $guestSessionId;

    protected function useGuestSession(): void
    {
        $this->guestSessionId = Str::random(40);
        // withCredentials() is required for postJson()'s cookies to be sent
        // at all (Laravel's JSON test client omits cookies by default,
        // mirroring a fetch() call without credentials: 'include') — without
        // it, every postJson() call looks like a brand new browser session.
        $this->withCredentials()->withCookie(config('session.cookie'), $this->guestSessionId);
    }

    protected function guestScope(): string
    {
        return CheckoutIntentGuard::scopeForSession($this->guestSessionId);
    }

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

    protected function vendorWithProduct(float $price = 20.00): array
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);

        return [$vendor, $product];
    }

    protected function payload(Vendor $vendor, Product $product, array $extra = []): array
    {
        return array_merge([
            'vendor_id' => $vendor->id,
            'category_id' => 'data',
            'service_id' => 'svc',
            'package_id' => (string) $product->id,
            'amount' => (float) $product->price,
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 0,
            'original_product_id' => $product->id,
        ], $extra);
    }

    protected function fakeInitializeAlwaysSucceeds(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/transaction/initialize')) {
                $body = $request->data();

                return Http::response([
                    'status' => true,
                    'message' => 'Initialized',
                    'data' => [
                        'authorization_url' => 'https://paystack.example/redirect',
                        'reference' => $body['reference'] ?? 'unknown',
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    protected function existingOrder(Vendor $vendor, Product $product, array $attrs): Order
    {
        return Order::create(array_merge([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => (float) $product->price,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'idempotency_scope' => $this->guestScope(),
        ], $attrs));
    }

    // A. DOUBLE SUBMIT — same checkout intent submitted twice -> one active attempt.
    public function test_double_submit_with_same_idempotency_key_creates_only_one_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();
        $this->fakeInitializeAlwaysSucceeds();

        $payload = $this->payload($vendor, $product, ['idempotency_key' => 'intent-1']);

        $first = $this->postJson(route('checkout.process'), $payload);
        $second = $this->postJson(route('checkout.process'), $payload);

        $first->assertOk()->assertJson(['success' => true]);
        // Second submit must not create a new order — it must be told to wait.
        $second->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    // B. CONCURRENT SUBMIT — two "concurrent" requests for the same intent -> one active attempt.
    public function test_concurrent_submit_race_creates_only_one_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();
        $this->fakeInitializeAlwaysSucceeds();

        // Simulate the second request's INSERT racing against the first by
        // pre-creating the row the DB unique index would have produced.
        $this->existingOrder($vendor, $product, [
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'race-intent',
        ]);

        $response = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'race-intent']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    // Phase 5 section 7 — three near-simultaneous submissions of the SAME
    // checkout intent (a plausible double-click + browser retry + duplicate
    // POST all landing together), followed by a confirmed failure and a
    // genuine retry. Real thread-level concurrency isn't reproducible in a
    // synchronous test process — each submission is a full round trip
    // before the next starts — but this is exactly how Phase 4's own
    // concurrent-race tests already model "someone else's request already
    // got there first"; the DB unique index (proven directly against a raw
    // duplicate INSERT in CheckoutIntentGuardTest) is what makes the outcome
    // identical for a genuinely simultaneous arrival.
    public function test_three_near_simultaneous_submissions_then_confirmed_failure_then_retry(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();

        // A single Http::fake() for the whole test: Http::fake() MERGES
        // stub callbacks rather than replacing them (Illuminate\Http\Client\
        // Factory::fake()), so a second call mid-test would never actually
        // override this one — a mutable flag is the correct way to change
        // behaviour partway through, exactly like the capstone tests above.
        $gatewayHasFailed = false;
        Http::fake(function ($request) use (&$gatewayHasFailed) {
            $url = (string) $request->url();
            if (str_contains($url, '/transaction/initialize')) {
                $body = $request->data();

                return Http::response(['status' => true, 'data' => ['authorization_url' => null, 'reference' => $body['reference'] ?? 'unknown']], 200);
            }
            if (str_contains($url, '/transaction/verify/')) {
                return Http::response(['status' => true, 'data' => ['status' => $gatewayHasFailed ? 'failed' : 'pending']], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $payload = $this->payload($vendor, $product, ['idempotency_key' => 'triple-submit-intent']);

        $r1 = $this->postJson(route('checkout.process'), $payload);
        $r2 = $this->postJson(route('checkout.process'), $payload);
        $r3 = $this->postJson(route('checkout.process'), $payload);

        $r1->assertOk()->assertJson(['success' => true]);
        $r2->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $r3->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);

        // One payable attempt, one initialization, one reference.
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
        $originalOrder = Order::sole();
        $originalReference = $originalOrder->payment_reference;
        $this->assertNotNull($originalReference);

        // The first reference now explicitly FAILS.
        $gatewayHasFailed = true;

        $retry = $this->postJson(route('checkout.process'), $payload);
        $retry->assertOk()->assertJson(['success' => true]);

        // Exactly one NEW attempt is permitted, with its own new reference.
        $this->assertDatabaseCount('orders', 2);
        $newOrder = Order::where('id', '!=', $originalOrder->id)->sole();
        $this->assertNotSame($originalReference, $newOrder->payment_reference);

        // The old reference remains terminal and untouched by the new attempt.
        $originalOrder->refresh();
        $this->assertSame('failed', $originalOrder->payment_status);
        $this->assertSame($originalReference, $originalOrder->payment_reference);
        $this->assertEquals(20.00, (float) $originalOrder->amount_paid);
    }

    // C. AMBIGUOUS PAYMENT — existing attempt verification times out -> no second order.
    public function test_ambiguous_verification_never_creates_a_second_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();

        $this->existingOrder($vendor, $product, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-UNKNOWN',
            'idempotency_key' => 'intent-unknown',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('timed out');
            },
        ]);

        $response = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'intent-unknown']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
        $this->assertSame('unpaid', Order::sole()->payment_status);
    }

    // D. PENDING PAYMENT — provider says pending -> no second order.
    public function test_provider_pending_never_creates_a_second_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();

        $this->existingOrder($vendor, $product, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-PENDING',
            'idempotency_key' => 'intent-pending',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $response = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'intent-pending']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('orders', 1);
    }

    // J. Assert zero initiation-gateway requests while an existing attempt is PENDING/UNKNOWN.
    public function test_no_new_gateway_call_when_existing_attempt_is_pending(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();

        $this->existingOrder($vendor, $product, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-PENDING-2',
            'idempotency_key' => 'intent-pending-2',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200),
            'https://api.paystack.co/transaction/initialize' => Http::response(['message' => 'must never be called'], 500),
        ]);

        $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'intent-pending-2']));

        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // Capstone — reproduces the exact Phase 3 residual vulnerability end-to-end.
    public function test_capstone_success_discovered_during_retry_never_double_charges(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();

        // Toggled from false -> true between "immediate browser verification"
        // and "customer retries" below — never by counting HTTP attempts,
        // since the client's own retry-on-ConnectionException would
        // otherwise make a single logical verify() call look like several.
        $gatewayHasSettled = false;
        Http::fake(function ($request) use (&$gatewayHasSettled) {
            $url = (string) $request->url();

            if (str_contains($url, '/transaction/initialize')) {
                $body = $request->data();

                return Http::response([
                    'status' => true,
                    'message' => 'Initialized',
                    'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'unknown'],
                ], 200);
            }

            if (str_contains($url, '/transaction/verify/')) {
                if (! $gatewayHasSettled) {
                    // The gateway actually charged the customer, but every
                    // attempt at the immediate browser-return verification
                    // (retries included) fails on the network.
                    throw new ConnectionException('timed out');
                }

                return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 2000]], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        // 1. Customer creates checkout intent; REF-A initialized.
        $payload = $this->payload($vendor, $product, ['idempotency_key' => 'capstone-intent']);
        $init = $this->postJson(route('checkout.process'), $payload);
        $init->assertOk()->assertJson(['success' => true]);
        $order = Order::sole();
        $this->assertSame('unpaid', $order->payment_status);

        // 2. Immediate browser-return verification becomes UNKNOWN (network failure).
        $verify = $this->postJson(route('checkout.verify'), ['reference' => $order->payment_reference]);
        $verify->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        // 3. Reconciliation would eventually discover this — simulate that
        // the gateway is now reachable and confirms the earlier charge.
        $gatewayHasSettled = true;

        // 4. Customer submits the SAME intent again before scheduled reconciliation.
        $retry = $this->postJson(route('checkout.process'), $payload);

        // 6. XTRA4U must NOT initialize REF-B.
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));

        // 7/8. Reconciliation (triggered synchronously by the retry) discovers
        // REF-A succeeded and completes it exactly once.
        $retry->assertOk()->assertJson(['success' => true]);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);

        // 10. Customer receives exactly ONE fulfillment worth of vendor earning.
        $vendor->refresh();
        $this->assertEqualsWithDelta(19.60, (float) $vendor->wallet_balance, 0.01);
    }

    // F. CONFIRMED FAILURE — provider authoritatively fails REF-A -> new attempt is safe.
    public function test_confirmed_failure_allows_a_genuinely_new_attempt(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();

        $this->existingOrder($vendor, $product, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-WILL-FAIL',
            'idempotency_key' => 'intent-fail',
        ]);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/transaction/verify/')) {
                return Http::response(['status' => true, 'data' => ['status' => 'failed']], 200);
            }
            if (str_contains($url, '/transaction/initialize')) {
                $body = $request->data();

                return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'x']], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $response = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'intent-fail']));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseHas('orders', ['payment_reference' => 'REF-WILL-FAIL', 'payment_status' => 'failed']);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    // G. LEGITIMATE SECOND PURCHASE — must always work, using a fresh intent key.
    public function test_intentional_second_purchase_of_same_product_works_after_first_completes(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();
        $this->fakeInitializeAlwaysSucceeds();

        $first = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'purchase-1']));
        $first->assertOk();
        $order1 = Order::sole();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 2000]], 200),
        ]);
        // Settlement requires proof a trusted source satisfied the order's
        // terms; a real verify/webhook stamps it via PaymentIntegrityGuard.
        app(\App\Services\Payments\PaymentIntegrityGuard::class)->stampTrustedSource(
            $order1,
            \App\Support\PaymentIntegrity::VERIFIED,
            'test fixture: gateway verified this payment'
        );
        app(\App\Services\PaymentService::class)->completeOrder($order1->fresh());
        $this->assertSame('paid', $order1->fresh()->payment_status);

        $this->fakeInitializeAlwaysSucceeds();
        // A NEW, distinct intent key from the SAME session — exactly what a
        // fresh page load / new "Buy again" click would generate.
        $second = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'purchase-2']));

        $second->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('orders', 2);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    // H. GATEWAY SWITCH — retrying REF-A must still query the ORIGINAL gateway.
    public function test_retry_after_default_gateway_switch_still_queries_original_gateway(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);
        [$vendor, $product] = $this->vendorWithProduct();

        $this->existingOrder($vendor, $product, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-GW-SWITCH',
            'idempotency_key' => 'intent-gw-switch',
        ]);

        // Admin switches the platform default AFTER REF-A was created.
        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 2000]], 200),
            'https://api.payaza.africa/*' => Http::response('must never be called', 500),
        ]);

        $response = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, ['idempotency_key' => 'intent-gw-switch']));

        Http::assertSent(fn ($r) => str_contains((string) $r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), 'payaza'));
        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('paid', Order::sole()->payment_status);
    }

    // I. PRICE CHANGE — REF-A keeps its originally-recorded expected amount.
    public function test_price_change_after_initiation_does_not_mutate_pending_order_amount(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct(10.00);
        $this->fakeInitializeAlwaysSucceeds();

        $init = $this->postJson(route('checkout.process'), $this->payload($vendor, $product, [
            'amount' => 10.00,
            'idempotency_key' => 'intent-price',
        ]));
        $init->assertOk();
        $order = Order::sole();
        $this->assertEquals(10.00, (float) $order->amount_paid);

        // Admin changes the product price.
        $product->update(['price' => 12.00]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200),
        ]);

        // Customer refreshes / retries the same intent.
        $this->postJson(route('checkout.process'), $this->payload($vendor, $product, [
            'amount' => 12.00,
            'idempotency_key' => 'intent-price',
        ]));

        // The existing order must still be reconciled against ITS original amount.
        $this->assertEquals(10.00, (float) $order->fresh()->amount_paid);
        $this->assertDatabaseCount('orders', 1);
    }

    // The other live Order-creation entry point: PurchaseController::store().
    public function test_purchase_controller_gateway_flow_also_deduplicates_double_submit(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $product] = $this->vendorWithProduct();
        $this->fakeInitializeAlwaysSucceeds();

        $payload = [
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'idempotency_key' => 'purchase-controller-intent',
        ];

        $first = $this->postJson(route('purchase'), $payload);
        $second = $this->postJson(route('purchase'), $payload);

        $first->assertOk();
        $second->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }
}
