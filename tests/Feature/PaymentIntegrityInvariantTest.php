<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalFulfillment;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\PaymentReconciliationService;
use App\Services\Payments\OrderPricingSnapshot;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Support\PaymentIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Security regression suite for the central payment integrity invariant:
 *
 *   NO ORDER MAY CREATE FINANCIAL SIDE EFFECTS OR ENTER EXTERNAL FULFILLMENT
 *   UNTIL THE SERVER HAS PROVEN THAT A TRUSTED PAYMENT SOURCE SATISFIED THE
 *   IMMUTABLE FINANCIAL REQUIREMENTS OF THAT EXACT ORDER.
 *
 * The scenarios here are modelled directly on the two audited incidents:
 *
 *   #83  — a reseller order whose own financial records described ~GHS 89 of
 *          value, recorded amount_paid = GHS 0.10, and still settled and
 *          delivered a 20GB bundle through SKDataPlug months later.
 *   #104 — a GHS 200 package whose order recorded amount_paid = GHS 0.10
 *          fourteen minutes after the product was created.
 */
class PaymentIntegrityInvariantTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function paystackConfig(): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
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
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    private function product(Vendor $vendor, float $price, string $name = 'MTN 20GB'): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => json_encode(['category' => 'data', 'network' => 'MTN']),
            'price' => $price,
            'is_active' => true,
            'is_resellable' => true,
        ]);
    }

    /** An order with its financial terms frozen exactly as checkout freezes them. */
    private function orderFor(Product $product, string $reference, string $gateway = 'paystack'): Order
    {
        $snapshot = OrderPricingSnapshot::forOwnedProduct($product);

        return Order::create([
            'recipient_phone_number' => '0596621893',
            'mobile_money_number' => '0596621893',
            'service_purchased' => $product->name,
            'amount_paid' => $snapshot['expected_amount'],
            ...$snapshot,
            'vendor_id' => $product->vendor_id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => $gateway,
            'payment_reference' => $reference,
        ]);
    }

    /**
     * Shape of a Paystack verify response (amount in pesewas).
     *
     * Answers dynamically off the requested URL so the reference it echoes
     * back always belongs to the order being verified. A static stub map would
     * answer the first registered pattern for every reference, which is both
     * unrealistic and would mask the reference-identity check. Pass
     * $forceReference to deliberately echo the WRONG reference.
     */
    private function fakePaystackVerify(
        float $amount,
        string $currency = 'GHS',
        string $status = 'success',
        ?string $forceReference = null,
        string $txnId = 'PSK-TXN-1'
    ): void {
        Http::fake(function ($request) use ($amount, $currency, $status, $forceReference, $txnId) {
            $requested = urldecode((string) basename(parse_url($request->url(), PHP_URL_PATH) ?: ''));

            return Http::response([
                'status' => true,
                'data' => [
                    'status' => $status,
                    'amount' => (int) round($amount * 100),
                    'currency' => $currency,
                    'reference' => $forceReference ?? $requested,
                    'id' => $txnId,
                ],
            ], 200);
        });
    }

    private function guard(): PaymentIntegrityGuard
    {
        return app(PaymentIntegrityGuard::class);
    }

    private function verificationFor(Order $order): array
    {
        return app(PaymentService::class)
            ->checkPaymentStatusForGateway($order->payment_reference, $order->payment_gateway);
    }

    // -----------------------------------------------------------------
    // 1. Client-submitted amounts are never authoritative (#104's origin)
    // -----------------------------------------------------------------

    public function test_client_submitted_amount_cannot_set_what_an_order_owes(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $product = $this->product($vendor, 200.00, 'MTN 50GB');

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'REF-104'],
            ], 200),
        ]);

        // The exact #104 shape: a GHS 200 package, a browser claiming GHS 0.10.
        $response = $this->postJson(route('checkout.process'), [
            'vendor_id' => $vendor->id,
            'service_id' => (string) $product->id,
            'package_id' => (string) $product->id,
            'original_product_id' => $product->id,
            'amount' => 0.10,
            'recipient_phone' => '0596621893',
        ]);

        $response->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('200.00', (string) $order->expected_amount, 'expected amount must come from the product, not the request');
        $this->assertSame('200.00', (string) $order->amount_paid);
        $this->assertNotNull($order->pricing_snapshot_at);
    }

    // -----------------------------------------------------------------
    // 2-4. Gateway-confirmed amount / currency must match EXACTLY
    // -----------------------------------------------------------------

    public function test_gateway_confirming_a_tiny_amount_never_settles_the_order(): void
    {
        // The #83/#104 signature: expected ~GHS 89, gateway confirms GHS 0.10.
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-83');

        $this->fakePaystackVerify(0.10);

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('amount_underpaid', $result->reason);

        $fresh = $order->fresh();
        $this->assertSame(PaymentIntegrity::MISMATCH, $fresh->payment_integrity_status);
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertSame('89.00', (string) $fresh->expected_amount, 'the frozen expectation must be untouched');
    }

    public function test_overpayment_is_also_refused_rather_than_silently_accepted(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-OVER');

        $this->fakePaystackVerify(150.00);

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('amount_overpaid', $result->reason);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_correct_amount_in_the_wrong_currency_is_refused(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-CUR');

        $this->fakePaystackVerify(89.00, currency: 'NGN');

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('currency_mismatch', $result->reason);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_a_reference_belonging_to_another_order_is_refused(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-MINE');

        // Gateway echoes back a different transaction's reference.
        $this->fakePaystackVerify(89.00, forceReference: 'REF-SOMEONE-ELSE');

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('reference_mismatch', $result->reason);
    }

    public function test_verification_against_a_different_gateway_is_refused(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-GW', gateway: 'moolre');

        $this->fakePaystackVerify(89.00);

        // A perfectly good Paystack confirmation, offered for an order that was
        // created under Moolre. The amount is even correct — it is the identity
        // of the paying gateway that is wrong.
        $verification = app(PaymentService::class)->checkPaymentStatus('REF-GW');
        $result = $this->guard()->guard($order, $verification, 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('gateway_mismatch', $result->reason);
    }

    // -----------------------------------------------------------------
    // 5-6. The happy path, exactly once, and single-use transactions
    // -----------------------------------------------------------------

    public function test_matching_amount_currency_and_reference_completes_exactly_once(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-OK');

        $this->fakePaystackVerify(89.00);

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');
        $this->assertTrue($result->passed);
        $this->assertSame(PaymentIntegrity::VERIFIED, $order->fresh()->payment_integrity_status);

        $paymentService = app(PaymentService::class);
        $this->assertTrue($paymentService->completeOrder($order->fresh()));
        // Idempotent: a second call must not double-credit.
        $this->assertTrue($paymentService->completeOrder($order->fresh()));

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
        $this->assertEqualsWithDelta(87.22, (float) $vendor->fresh()->wallet_balance, 0.001);
    }

    public function test_one_gateway_transaction_cannot_pay_two_orders(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);

        $first = $this->orderFor($product, 'REF-A');
        $second = $this->orderFor($product, 'REF-B');

        $this->fakePaystackVerify(89.00, txnId: 'SHARED-TXN');
        $this->assertTrue($this->guard()->guard($first, $this->verificationFor($first), 'paystack')->passed);
        app(PaymentService::class)->completeOrder($first->fresh());
        $this->assertSame('paid', $first->fresh()->payment_status);

        // The same gateway transaction id now turns up for a second order.
        $this->fakePaystackVerify(89.00, txnId: 'SHARED-TXN');
        $result = $this->guard()->guard($second, $this->verificationFor($second), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('gateway_transaction_already_consumed', $result->reason);
        $this->assertSame('unpaid', $second->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // 7-9. No surface may bypass the invariant
    // -----------------------------------------------------------------

    public function test_callback_cannot_settle_an_underpaid_order_or_change_its_expected_amount(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-CB');

        $this->fakePaystackVerify(0.10);

        $this->get(route('payment.callback', ['reference' => 'REF-CB']));

        $fresh = $order->fresh();
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertSame('89.00', (string) $fresh->expected_amount);
        $this->assertSame(PaymentIntegrity::MISMATCH, $fresh->payment_integrity_status);
    }

    public function test_paystack_webhook_cannot_bypass_amount_validation(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-WH');

        $this->fakePaystackVerify(0.10);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'REF-WH', 'status' => 'success', 'amount' => 10]];
        $raw = json_encode($payload);

        $this->call(
            'POST',
            route('webhooks.paystack'),
            [],
            [],
            [],
            ['HTTP_X-PAYSTACK-SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_123'), 'CONTENT_TYPE' => 'application/json'],
            $raw
        );

        $fresh = $order->fresh();
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    public function test_reconciliation_cannot_bypass_amount_validation(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-RECON');

        $this->fakePaystackVerify(0.10);

        $outcome = app(PaymentReconciliationService::class)->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_INTEGRITY_MISMATCH, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $vendor->fresh()->wallet_balance, 0.001);
    }

    // -----------------------------------------------------------------
    // 12-15. A failed check must have no financial side effects at all
    // -----------------------------------------------------------------

    public function test_failed_integrity_credits_no_wallet_creates_no_transaction_and_dispatches_no_fulfillment(): void
    {
        Queue::fake();
        $this->paystackConfig();

        $owner = Vendor::factory()->create();
        $reseller = Vendor::factory()->create();
        $product = $this->product($owner, 88.00);
        $listing = ResellerProduct::create([
            'product_id' => $product->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_vendor_id' => $owner->id,
            'base_price' => 88.00,
            'markup_price' => 1.00,
            'is_active' => true,
        ]);

        // Exactly order #83: base 88 + markup 1 = 89 expected.
        $snapshot = OrderPricingSnapshot::forResellerListing($listing);
        $order = Order::create([
            'recipient_phone_number' => '0596621893',
            'mobile_money_number' => '0596621893',
            'service_purchased' => $product->name,
            'amount_paid' => $snapshot['expected_amount'],
            ...$snapshot,
            'vendor_id' => $reseller->id,
            'vendor_service_id' => $product->id,
            'reseller_product_id' => $listing->id,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'is_reseller_order' => true,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'XTRA4U-MLR-83',
        ]);

        $this->assertSame('89.00', (string) $order->expected_amount);

        $this->fakePaystackVerify(0.10);
        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');
        $this->assertFalse($result->passed);

        // completeOrder() must refuse even when called directly.
        $this->assertFalse(app(PaymentService::class)->completeOrder($order->fresh()));

        $fresh = $order->fresh();
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $owner->fresh()->wallet_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $reseller->fresh()->wallet_balance, 0.001);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->whereIn('payment_status', ['successful', 'completed'])->count());
        $this->assertFalse($fresh->allowsFulfillment());
        Queue::assertNotPushed(ProcessExternalFulfillment::class);
    }

    // -----------------------------------------------------------------
    // 22. A legacy order can never be revived into a delivery
    // -----------------------------------------------------------------

    public function test_a_legacy_order_with_unprovable_terms_is_parked_not_completed(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);

        // Order #83's situation: no frozen snapshot, and an amount_paid that
        // disagrees with the listing it points at — so there is nothing to
        // corroborate it against and its terms cannot be established.
        $order = $this->orderFor($product, 'XTRA4U-MLR-69530f3135f3b-83');
        $order->forceFill([
            'expected_amount' => null,
            'currency' => null,
            'pricing_snapshot_at' => null,
            'amount_paid' => 0.10,
            'payment_integrity_status' => PaymentIntegrity::LEGACY_UNVERIFIED,
            'created_at' => '2025-12-30 10:00:00',
        ])->save();

        // The gateway happily confirms that same 0.10 — under the old logic
        // this "matched" and the order settled and delivered 20GB.
        $this->fakePaystackVerify(0.10);

        $outcome = app(PaymentReconciliationService::class)->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_MAX_WINDOW_EXCEEDED, $outcome);

        $fresh = $order->fresh();
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertSame(PaymentIntegrity::MANUAL_REVIEW, $fresh->payment_integrity_status);
        $this->assertFalse($fresh->allowsFulfillment());
        $this->assertEqualsWithDelta(0.0, (float) $vendor->fresh()->wallet_balance, 0.001);

        // Parked: never picked up by the automatic sweep again.
        $this->assertTrue($fresh->next_reconciliation_at->greaterThan(now()->addYears(5)));
    }

    // -----------------------------------------------------------------
    // Legacy expected-amount reconstruction: corroboration, not a date
    // -----------------------------------------------------------------

    /** A legacy order with no snapshot, stripped of its frozen terms. */
    private function legacyOrder(Product $product, string $reference, float $amountPaid, string $createdAt = '2026-01-15 10:00:00'): Order
    {
        $order = $this->orderFor($product, $reference);

        $order->forceFill([
            'expected_amount' => null,
            'currency' => null,
            'pricing_snapshot_at' => null,
            'base_price' => null,
            'markup_price' => null,
            'amount_paid' => $amountPaid,
            'payment_integrity_status' => PaymentIntegrity::LEGACY_UNVERIFIED,
            'created_at' => $createdAt,
        ])->save();

        return $order->fresh();
    }

    public function test_a_legacy_order_corroborated_by_its_own_unchanged_product_can_still_settle(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);

        // Product last touched BEFORE the order existed, and the order's
        // recorded amount agrees with it: two independent records concur, and
        // the price demonstrably did not move in between.
        $product->forceFill(['updated_at' => now()->subDays(2)])->save();
        $order = $this->legacyOrder($product, 'REF-LEGACY-OK', 89.00, now()->subHour()->toDateTimeString());

        $this->fakePaystackVerify(89.00);
        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertTrue($result->passed);
        $this->assertSame('corroborated', $result->expectedAmountSource);
        $this->assertStringContainsString('expected_from=corroborated', $order->fresh()->payment_integrity_note);
    }

    public function test_a_legacy_order_whose_product_was_repriced_since_cannot_be_reconstructed(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $product->forceFill(['updated_at' => now()->subDays(2)])->save();
        $order = $this->legacyOrder($product, 'REF-LEGACY-DRIFT', 89.00, now()->subHour()->toDateTimeString());

        // The owner re-prices AFTER the order was created. Today's price is now
        // no evidence of what the price was then — even though it would still
        // "match" if we naively compared the two numbers at face value.
        $product->update(['price' => 120.00]);

        $this->fakePaystackVerify(89.00);
        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('expected_amount_unprovable', $result->reason);
        $this->assertSame(PaymentIntegrity::MANUAL_REVIEW, $order->fresh()->payment_integrity_status);
    }

    public function test_a_recent_legacy_order_is_not_trusted_merely_for_being_recent(): void
    {
        // The withdrawn assumption: "created after the 2026-09-12 pricing fix,
        // therefore amount_paid is trustworthy". A date says nothing about an
        // individual order — the /purchase wallet bypass produced orders with
        // correct server-derived amounts and no payment at all.
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);

        // Created minutes ago, but its recorded amount disagrees with its own
        // product, so there is nothing to corroborate.
        $order = $this->legacyOrder($product, 'REF-RECENT', 0.10, now()->subMinutes(10)->toDateTimeString());

        $this->fakePaystackVerify(0.10);
        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame('expected_amount_unprovable', $result->reason);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_reconstruction_can_be_disabled_entirely_for_maximum_strictness(): void
    {
        config(['payments.reconstruct_legacy_expected_amount' => false]);

        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $product->forceFill(['updated_at' => now()->subDays(2)])->save();
        $order = $this->legacyOrder($product, 'REF-STRICT', 89.00, now()->subHour()->toDateTimeString());

        $this->fakePaystackVerify(89.00);
        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertFalse($result->passed);
        $this->assertSame(PaymentIntegrity::MANUAL_REVIEW, $order->fresh()->payment_integrity_status);
    }

    // -----------------------------------------------------------------
    // Verified must never claim a check the provider made impossible
    // -----------------------------------------------------------------

    public function test_a_provider_that_reports_no_currency_records_it_as_assumed_not_confirmed(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-NOCUR');

        // Moolre's status response carries no currency field; this is that shape.
        Http::fake(fn ($request) => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'amount' => 8900,
                'reference' => basename(parse_url($request->url(), PHP_URL_PATH)),
                'id' => 'TXN-NOCUR',
            ],
        ], 200));

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertTrue($result->passed);
        $this->assertFalse($result->currencyIndependentlyConfirmed());

        $fresh = $order->fresh();
        $this->assertSame('assumed', $fresh->payment_verified_aspects['currency']);
        $this->assertSame('confirmed', $fresh->payment_verified_aspects['amount']);
        $this->assertStringContainsString('currency=assumed', $fresh->payment_integrity_note);
        $this->assertNull($fresh->gateway_confirmed_currency);
    }

    public function test_a_provider_that_reports_currency_records_it_as_confirmed(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-CUROK');

        $this->fakePaystackVerify(89.00, currency: 'GHS');

        $result = $this->guard()->guard($order, $this->verificationFor($order), 'paystack');

        $this->assertTrue($result->passed);
        $this->assertTrue($result->currencyIndependentlyConfirmed());
        $this->assertSame('confirmed', $order->fresh()->payment_verified_aspects['currency']);
        $this->assertSame('GHS', $order->fresh()->gateway_confirmed_currency);
    }

    // -----------------------------------------------------------------
    // 15/K. Fulfillment eligibility
    // -----------------------------------------------------------------

    public function test_paid_alone_does_not_make_an_order_fulfillable(): void
    {
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-FUL');

        // Marked paid without any proof — the state an unverified legacy
        // completion leaves behind.
        $order->forceFill([
            'payment_status' => 'paid',
            'payment_integrity_status' => PaymentIntegrity::LEGACY_UNVERIFIED,
        ])->save();

        $this->assertFalse($order->fresh()->allowsFulfillment());

        $order->forceFill(['payment_integrity_status' => PaymentIntegrity::VERIFIED])->save();
        $this->assertTrue($order->fresh()->allowsFulfillment());
    }

    // -----------------------------------------------------------------
    // 10-11. Races stay idempotent
    // -----------------------------------------------------------------

    public function test_browser_verification_and_webhook_race_settles_once(): void
    {
        $this->paystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 89.00);
        $order = $this->orderFor($product, 'REF-RACE');

        $this->fakePaystackVerify(89.00);

        // Browser verify.
        $this->postJson(route('checkout.verify'), ['reference' => 'REF-RACE'])->assertOk();
        // Redirect callback arrives for the same reference.
        $this->get(route('payment.callback', ['reference' => 'REF-RACE']));
        // And so does a reconciliation pass.
        app(PaymentReconciliationService::class)->reconcile($order->fresh());

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
        $this->assertEqualsWithDelta(87.22, (float) $vendor->fresh()->wallet_balance, 0.001);
    }
}
