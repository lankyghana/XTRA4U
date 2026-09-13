<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationServiceUssdTest extends TestCase
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

    protected function starter(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    protected function pendingSubscription(string $reference, string $gateway = 'paystack'): UssdSubscription
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        return UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => $reference,
            'payment_gateway' => $gateway,
        ]);
    }

    protected function service(): PaymentReconciliationService
    {
        return app(PaymentReconciliationService::class);
    }

    // A. SUCCESS
    public function test_success_activates_exactly_once(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-A');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => (int) ($subscription->price_paid * 100)],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($subscription);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    // B. FAILED
    // Phase 5: USSD subscription failure now normalizes onto an explicit
    // terminal status (STATUS_PAYMENT_FAILED), matching Order/
    // AfaRegistration/ResultCheckerOrder/WalletTopup, which all already
    // transition to a terminal state on a provider-confirmed failure. Before
    // this, `status` stayed pending_payment forever (only the
    // PAYMENT_FAILED event recorded it), so the reconciler kept re-querying
    // an already-dead reference on its backoff schedule.
    public function test_authoritative_failure_is_recorded_with_a_terminal_status_change(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-B');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($subscription);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame(UssdSubscription::STATUS_PAYMENT_FAILED, $subscription->fresh()->status);
        $this->assertTrue(
            \App\Models\UssdSubscriptionEvent::where('ussd_subscription_id', $subscription->id)
                ->where('event', \App\Models\UssdSubscriptionEvent::PAYMENT_FAILED)
                ->exists(),
            'A PAYMENT_FAILED event must still be recorded.'
        );
    }

    // C. PENDING
    public function test_provider_pending_stays_pending(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-C');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($subscription);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, $subscription->fresh()->status);
    }

    // D. UNKNOWN / network error
    public function test_network_error_stays_unresolved(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-D');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $outcome = $this->service()->reconcile($subscription);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, $subscription->fresh()->status);
    }

    // E. ORIGINAL GATEWAY after default changes
    public function test_original_gateway_is_queried_after_default_changes(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);
        $subscription = $this->pendingSubscription('USSD-E', 'paystack');

        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => (int) ($subscription->price_paid * 100)],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $outcome = $this->service()->reconcile($subscription);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payaza'));
        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
    }

    // F. DUPLICATE RECONCILIATION
    public function test_duplicate_reconciliation_activates_exactly_once(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-F');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => (int) ($subscription->price_paid * 100)],
            ], 200),
        ]);

        $this->service()->reconcile($subscription);
        $activatedAt = $subscription->fresh()->activated_at;

        $this->service()->reconcile($subscription->fresh());

        $this->assertEquals($activatedAt, $subscription->fresh()->activated_at, 'Duplicate reconciliation must not re-activate.');
    }

    // G. WEBHOOK RACE (simulated: a prior call already activated it)
    public function test_already_active_subscription_is_not_clobbered_by_a_racing_failure_verdict(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-G');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => (int) ($subscription->price_paid * 100)],
            ], 200),
        ]);
        $this->service()->reconcile($subscription);
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->fresh()->status);

        // A second, contradictory verification result must not undo activation
        // — verifyAndActivate() itself is idempotent on an ACTIVE subscription.
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($subscription->fresh());

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    // H. INTEGRITY MISMATCH
    // Note: unlike Order/AfaRegistration/ResultCheckerOrder/WalletTopup
    // (which leave an amount mismatch pending for manual review — see
    // OUTCOME_INTEGRITY_MISMATCH), USSD's own UssdSubscriptionPurchaseService::
    // verifyAndActivate() has always routed an amount mismatch through the
    // exact same failPayment() call as a terminal gateway failure (pre-
    // existing, unrelated to Phase 5). Phase 5 only changed what
    // failPayment() itself does — it now also sets the terminal status —
    // so an amount mismatch now correctly stops looking like "still
    // pending" here too.
    public function test_success_with_wrong_amount_does_not_activate(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-H');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 100],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($subscription);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame(UssdSubscription::STATUS_PAYMENT_FAILED, $subscription->fresh()->status);
    }

    public function test_no_recorded_gateway_is_parked_without_any_gateway_call(): void
    {
        $this->makePaystackConfig();
        $subscription = $this->pendingSubscription('USSD-NO-GW');
        DB::table('ussd_subscriptions')->where('id', $subscription->id)->update(['payment_gateway' => null]);

        Http::fake();

        $outcome = $this->service()->reconcile($subscription->fresh());

        Http::assertNothingSent();
        $this->assertSame(PaymentReconciliationService::OUTCOME_NO_GATEWAY, $outcome);
    }
}
