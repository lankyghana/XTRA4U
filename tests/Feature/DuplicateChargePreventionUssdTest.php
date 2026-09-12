<?php

namespace Tests\Feature;

use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 4 — UssdSubscription surface (UssdSubscriptionPurchaseService::
 * initiate(), reached via `vendor.ussd.subscription.purchase`).
 * Vendor-authenticated (actingAs persists across requests without any
 * cookie juggling, unlike the guest/session-scoped surfaces).
 */
class DuplicateChargePreventionUssdTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $verifications = [];

    /** @var array<string, mixed> */
    private array $initiateResult = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->initiateResult = [
            'success' => true,
            'reference' => null,
            'authorization_url' => 'https://gateway.test/pay/abc',
            'gateway_name' => 'paystack',
        ];

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('isReady')->andReturn(true);
        $mock->shouldReceive('initiateGenericPayment')->andReturnUsing(fn () => $this->initiateResult);
        $mock->shouldReceive('checkPaymentStatusForGateway')->andReturnUsing(function (string $reference) {
            return $this->verifications[$reference] ?? ['success' => false, 'message' => 'Not found'];
        });

        $this->app->instance(PaymentService::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function starter(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    private function settles(string $reference, float $amount, string $status = 'success'): void
    {
        $this->verifications[$reference] = ['success' => true, 'data' => ['status' => $status, 'amount' => $amount]];
    }

    private function existingSubscription(Vendor $vendor, UssdPlan $plan, array $attrs): UssdSubscription
    {
        return UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'idempotency_scope' => CheckoutIntentGuard::scopeForVendor($vendor->id),
        ], $attrs));
    }

    private function purchase(Vendor $vendor, UssdPlan $plan, ?string $idempotencyKey)
    {
        return $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), array_filter([
                'plan_id' => $plan->id,
                'idempotency_key' => $idempotencyKey,
            ], fn ($v) => $v !== null));
    }

    // A. DOUBLE SUBMIT
    public function test_double_submit_with_same_idempotency_key_creates_only_one_subscription(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $first = $this->purchase($vendor, $plan, 'ussd-intent-1');
        $second = $this->purchase($vendor, $plan, 'ussd-intent-1');

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('ussd_subscriptions', 1);
    }

    // B. CONCURRENT SUBMIT
    public function test_concurrent_submit_race_creates_only_one_subscription(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $this->existingSubscription($vendor, $plan, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'ussd-race-ref',
            'idempotency_key' => 'ussd-race',
        ]);

        $response = $this->purchase($vendor, $plan, 'ussd-race');

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('ussd_subscriptions', 1);
    }

    // C. AMBIGUOUS PAYMENT (verification call itself failed / unknown)
    public function test_ambiguous_verification_never_creates_a_second_subscription(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $this->existingSubscription($vendor, $plan, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'ussd-ref-unknown',
            'idempotency_key' => 'ussd-unknown',
        ]);
        // No settles() entry recorded: PaymentService double returns
        // success=false ("Not found") — the network/verification failure case.

        $response = $this->purchase($vendor, $plan, 'ussd-unknown');

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('ussd_subscriptions', 1);
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, UssdSubscription::sole()->status);
    }

    // D. PENDING PAYMENT
    public function test_provider_pending_never_creates_a_second_subscription(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $this->existingSubscription($vendor, $plan, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'ussd-ref-pending',
            'idempotency_key' => 'ussd-pending',
        ]);
        $this->settles('ussd-ref-pending', (float) $plan->price, 'pending');

        $response = $this->purchase($vendor, $plan, 'ussd-pending');

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('ussd_subscriptions', 1);
    }

    // Capstone
    public function test_capstone_success_discovered_during_retry_never_double_charges(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $init = $this->purchase($vendor, $plan, 'ussd-capstone');
        $init->assertOk()->assertJson(['success' => true]);
        $subscription = UssdSubscription::sole();
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, $subscription->status);

        // Immediate polling sees nothing conclusive yet (verification call itself unresolved).
        // (No settles() entry yet — same as the ambiguous case above.)

        // Reconciliation would eventually discover this; simulate the gateway
        // now confirming the earlier charge before the customer retries.
        $this->settles($subscription->payment_reference, (float) $plan->price);

        $retry = $this->purchase($vendor, $plan, 'ussd-capstone');

        $this->assertDatabaseCount('ussd_subscriptions', 1);
        $retry->assertOk()->assertJson(['success' => true, 'status' => 'paid']);
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    // F. CONFIRMED FAILURE
    public function test_confirmed_failure_allows_a_genuinely_new_attempt(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $this->existingSubscription($vendor, $plan, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'ussd-ref-fail',
            'idempotency_key' => 'ussd-fail',
        ]);
        $this->settles('ussd-ref-fail', (float) $plan->price, 'failed');

        $response = $this->purchase($vendor, $plan, 'ussd-fail');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('ussd_subscriptions', 2);
        // A confirmed-failed USSD payment stays STATUS_PENDING_PAYMENT (see
        // UssdSubscriptionPurchaseService::isTerminallyFailed()) — the
        // PAYMENT_FAILED event is the authoritative terminal-failure signal.
        $failedSubscription = UssdSubscription::where('payment_reference', 'ussd-ref-fail')->sole();
        $this->assertDatabaseHas('ussd_subscription_events', [
            'ussd_subscription_id' => $failedSubscription->id,
            'event' => \App\Models\UssdSubscriptionEvent::PAYMENT_FAILED,
        ]);
    }

    // G. LEGITIMATE SECOND PURCHASE (renewal) after the first activates.
    public function test_intentional_renewal_works_after_first_subscription_activates(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $first = $this->purchase($vendor, $plan, 'ussd-purchase-1');
        $first->assertOk();
        $subscription1 = UssdSubscription::sole();
        $this->settles($subscription1->payment_reference, (float) $plan->price);
        app(\App\Services\Ussd\UssdSubscriptionPurchaseService::class)->verifyAndActivate($subscription1->payment_reference);
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription1->fresh()->status);

        $second = $this->purchase($vendor, $plan, 'ussd-purchase-2');

        $second->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('ussd_subscriptions', 2);
    }

    // Security: cross-vendor key reuse must never resolve another vendor's subscription.
    public function test_idempotency_key_is_scoped_and_cannot_be_reused_across_vendors(): void
    {
        $victim = Vendor::factory()->create();
        $plan = $this->starter();

        $victimSubscription = $this->existingSubscription($victim, $plan, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'ussd-victim-ref',
            'idempotency_key' => 'shared-ussd-key',
        ]);

        $attacker = Vendor::factory()->create();
        $response = $this->purchase($attacker, $plan, 'shared-ussd-key');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('ussd_subscriptions', 2);
        $newSubscription = UssdSubscription::where('id', '!=', $victimSubscription->id)->sole();
        $this->assertSame($attacker->id, $newSubscription->vendor_id);
    }
}
