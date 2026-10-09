<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\User;
use App\Models\UtilityBillOrder;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Support\PaymentFailureTransition;
use App\Support\PaymentIntegrity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The browser verify (and redirect callback) can learn "failed" from the gateway while the
 * success webhook settles the same order. A settled order must never be downgraded, and the
 * customer page must always reflect the persisted state. (Real row-lock concurrency is covered
 * against MySQL in UtilityBillPaymentRaceMySqlTest.)
 */
class UtilityBillPaymentRaceTest extends UtilityBillTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_123', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);
    }

    private function gatewayBody(string $status, string $ref): array
    {
        return ['status' => true, 'data' => ['status' => $status, 'amount' => 10000, 'currency' => 'GHS', 'reference' => $ref, 'id' => 987654]];
    }

    /** The webhook's real completion path: integrity guard, then canonical completeOrder. */
    private function settleLikeWebhook(int $orderId): void
    {
        $order = Order::query()->findOrFail($orderId);
        $verification = ['success' => true, 'data' => ['status' => 'success', 'amount' => 100.0, 'currency' => 'GHS', 'reference' => $order->payment_reference, 'id' => 987654]];
        $integrity = app(PaymentIntegrityGuard::class)->guard($order, $verification, 'paystack');
        $this->assertTrue($integrity->passed, 'integrity guard should accept the exact payment');
        $order->amount_paid = $integrity->confirmedAmount;
        app(PaymentService::class)->completeOrder($order);
    }

    private function payCalls(): int
    {
        return Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'))->count();
    }

    private function signedWebhook(string $ref)
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => $ref]];

        return $this->withHeaders(['x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'sk_test_123')])
            ->postJson(route('webhooks.paystack'), $payload);
    }

    private function assertPaymentProofIntact(UtilityBillOrder $u): void
    {
        $o = $u->order()->first();
        $this->assertSame('paid', $o->payment_status);
        $this->assertSame('Processing', $o->status);
        $this->assertNotNull($o->payment_completed_at);
        $this->assertSame(PaymentIntegrity::VERIFIED, $o->payment_integrity_status);
        $this->assertSame('100.00', (string) $o->gateway_confirmed_amount);
    }

    // ---- Test A: webhook completes while the browser verify waits on the gateway ----------------

    public function test_a_webhook_settling_during_a_failed_browser_verify_keeps_the_order_paid(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $ref = $u->order->payment_reference;

        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            // The gateway call is where the webhook lands: the browser read the order as unpaid,
            // then (while it waits) the webhook settles it, then the gateway answers "failed".
            'https://api.paystack.co/transaction/verify/*' => function () use ($u, $ref) {
                $this->settleLikeWebhook($u->order_id);

                return Http::response($this->gatewayBody('failed', $ref));
            },
        ]);

        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()
            ->assertJson(['status' => 'success', 'redirect' => $u->statusUrl()]);

        $u->refresh();
        $this->assertPaymentProofIntact($u);
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);   // not downgraded
        $this->assertSame(1, $this->payCalls());                                           // fulfilled once
        $this->assertSame(1, $u->events()->where('kind', 'payment_confirmed')->count());

        $this->getJson(route('utility-bills.poll', $u->access_token))
            ->assertJson(['stage' => 'processing', 'headline' => 'Payment received', 'detail' => 'Your utility bill is being processed.']);
        $this->get($u->statusUrl())->assertOk()->assertSee('Payment received')->assertDontSee('could not be confirmed');
    }

    public function test_a_browser_verify_after_the_real_webhook_reports_the_settled_payment(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $ref = $u->order->payment_reference;

        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            'https://api.paystack.co/transaction/verify/*' => Http::sequence()
                ->push($this->gatewayBody('success', $ref))      // the webhook's own verification
                ->push($this->gatewayBody('failed', $ref))       // a contradictory later read
                ->push($this->gatewayBody('failed', $ref)),
        ]);

        $this->signedWebhook($ref)->assertOk();
        $this->assertSame('paid', $u->order->fresh()->payment_status);

        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()
            ->assertJson(['status' => 'success', 'redirect' => $u->statusUrl()]);

        $this->assertPaymentProofIntact($u->fresh());
        $this->assertSame(1, $this->payCalls());
    }

    // ---- Test B: a genuinely unpaid order may fail, and stays recoverable ---------------------

    public function test_b_failed_verify_on_a_genuinely_unpaid_order_marks_it_failed_and_a_later_trusted_success_still_settles_it(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $ref = $u->order->payment_reference;

        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            'https://api.paystack.co/transaction/verify/*' => Http::sequence()
                ->push($this->gatewayBody('failed', $ref))
                ->push($this->gatewayBody('success', $ref)),
        ]);

        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()
            ->assertJson(['status' => 'failed', 'message' => 'Payment could not be confirmed.']);

        $u->refresh();
        $this->assertSame('failed', $u->order->payment_status);
        $this->assertSame('Failed', $u->order->status);
        $this->assertNull($u->order->payment_completed_at);
        $this->assertSame(FulfillmentStatus::AWAITING_PAYMENT, $u->fulfillment_status);
        $this->assertSame(0, $this->payCalls());
        $this->getJson(route('utility-bills.poll', $u->access_token))
            ->assertJson(['stage' => 'payment_failed', 'headline' => 'Payment could not be confirmed']);

        // A later trusted success (signed webhook, independently re-verified) still settles it.
        $this->signedWebhook($ref)->assertOk();

        $u->refresh();
        $this->assertPaymentProofIntact($u);
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);
        $this->assertSame(1, $this->payCalls());
    }

    // ---- Test C (single process): every interleaving of the two writers ------------------------

    public function test_c_failure_is_refused_while_trusted_proof_is_held_and_completion_is_in_flight(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $ref = $u->order->payment_reference;

        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            // The webhook's guard has stamped VERIFIED, but completeOrder() has not run yet.
            'https://api.paystack.co/transaction/verify/*' => function () use ($u, $ref) {
                $this->prove(Order::query()->findOrFail($u->order_id));

                return Http::response($this->gatewayBody('failed', $ref));
            },
        ]);

        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame('unpaid', $u->order->fresh()->payment_status);

        app(PaymentService::class)->completeOrder($u->order->fresh());
        $this->assertSame('paid', $u->order->fresh()->payment_status);
        $this->assertSame(1, $this->payCalls());
    }

    public function test_c_redirect_callback_failed_branch_never_downgrades_a_settled_order(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $ref = $u->order->payment_reference;

        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            'https://api.paystack.co/transaction/verify/*' => function () use ($u, $ref) {
                $this->settleLikeWebhook($u->order_id);

                return Http::response($this->gatewayBody('failed', $ref));
            },
        ]);

        $this->get('/payment/callback?reference='.$ref)->assertRedirect($u->statusUrl());

        $this->assertPaymentProofIntact($u->fresh());
        $this->assertSame(1, $this->payCalls());
    }

    public function test_c_shared_checkout_verify_never_downgrades_a_settled_utility_order(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $ref = $u->order->payment_reference;

        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            'https://api.paystack.co/transaction/verify/*' => function () use ($u, $ref) {
                $this->settleLikeWebhook($u->order_id);

                return Http::response($this->gatewayBody('failed', $ref));
            },
        ]);

        $this->postJson(route('checkout.verify'), ['reference' => $ref])->assertOk()->assertJson(['status' => 'success']);
        $this->assertPaymentProofIntact($u->fresh());
    }

    // ---- Test D: paid, provider needs attention ---------------------------------------------

    public function test_d_paid_order_needing_provider_attention_shows_payment_received_to_the_customer_and_attention_to_admin(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Unauthorized'], 401)]);
        $u = $this->makeOrder(['vendor' => null, 'paid' => true]);

        $this->assertSame(FulfillmentStatus::ATTENTION, $u->fresh()->fulfillment_status);

        $this->getJson(route('utility-bills.poll', $u->access_token))->assertJson([
            'stage' => 'delayed',
            'headline' => 'Payment received',
            'detail' => 'Your utility bill is taking longer than expected. We are reviewing it. Please keep your reference.',
        ]);
        $this->get($u->statusUrl())->assertOk()->assertSee('Payment received')
            ->assertDontSee('could not be confirmed')->assertDontSee('Payment was not successful');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.utility-bill-sales.index', ['fulfillment' => 'attention']))->assertOk()->assertSee($u->public_ref);
        $this->get(route('admin.utility-bill-sales.show', $u))->assertOk()->assertSee('Needs attention');
    }

    public function test_d_a_settled_order_whose_payment_status_was_corrupted_still_shows_payment_received(): void
    {
        // The pre-fix race left exactly this: proof + settlement, but payment_status overwritten to failed.
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['vendor' => null, 'paid' => true]);
        Order::query()->whereKey($u->order_id)->update(['payment_status' => 'failed', 'status' => 'Failed']);

        $this->getJson(route('utility-bills.poll', $u->access_token))->assertJson(['stage' => 'processing', 'headline' => 'Payment received']);
    }

    // ---- Test E: repeated failed verifications after the webhook --------------------------

    public function test_e_repeated_failed_verifications_after_settlement_are_idempotent(): void
    {
        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody()),
            'https://api.paystack.co/transaction/verify/*' => Http::response($this->gatewayBody('failed', 'x')),
        ]);
        $u = $this->makeOrder(['vendor' => null, 'paid' => true]);
        $before = $u->order()->first()->only(['payment_status', 'status', 'payment_completed_at', 'payment_integrity_status', 'gateway_confirmed_amount', 'updated_at']);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(PaymentFailureTransition::PAID, PaymentFailureTransition::apply($u->order_id));
            $this->postJson(route('utility-bills.verify'), ['reference' => $u->order->payment_reference])->assertOk()
                ->assertJson(['status' => 'success', 'redirect' => $u->statusUrl()]);
        }

        $this->assertEquals($before, $u->order()->first()->only(array_keys($before)));
        $this->assertSame(1, $this->payCalls());
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fresh()->fulfillment_status);
    }

    public function test_transition_only_applies_to_genuinely_unsettled_orders(): void
    {
        $u = $this->makeOrder(['vendor' => null]);
        $id = $u->order_id;

        $this->assertSame(PaymentFailureTransition::FAILED, PaymentFailureTransition::apply($id));
        $this->assertSame(PaymentFailureTransition::KEPT, PaymentFailureTransition::apply($id));   // already failed: no rewrite

        Order::query()->whereKey($id)->update(['payment_status' => 'unpaid', 'status' => 'Pending', 'payment_completed_at' => now()]);
        $this->assertSame(PaymentFailureTransition::KEPT, PaymentFailureTransition::apply($id));   // completion time recorded
        $this->assertSame('unpaid', Order::query()->whereKey($id)->value('payment_status'));
    }
}
