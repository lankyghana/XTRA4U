<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\SandboxOrder;
use App\Models\Developer\SandboxOrderEvent;
use App\Models\Developer\SandboxPayment;
use App\Services\GatewayManager;

class SandboxPaymentTest extends SandboxTestCase
{
    private function pendingOrder(?array $w = null, string $idem = 'k1'): array
    {
        $w ??= $this->sandboxWorld(10.0);
        $id = $this->sandboxOrderId($w['key'], $w['offer'], $idem);
        $pay = $this->startPayment($w['key'], $id)->assertStatus(202);

        return [$w, $id, $pay->json('data.reference')];
    }

    // ── starting a payment ───────────────────────────────────────────────────

    public function test_a_simulated_payment_starts_pending_and_is_clearly_simulated(): void
    {
        $w = $this->sandboxWorld(10.0);
        $this->forbidProductionServices();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);

        $res = $this->startPayment($w['key'], $id)->assertStatus(202);

        $res->assertJsonPath('data.order_id', $id)
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 10)
            ->assertJsonPath('data.method', 'simulated_mobile_money')
            ->assertJsonPath('data.flow_type', 'simulated')
            ->assertJsonPath('data.authorization_url', null)
            ->assertJsonPath('data.checkout_config', null);
        $this->assertStringContainsString('No real payment', $res->json('message'));
        $this->assertMatchesRegularExpression('/^sbx_pay_[a-z0-9]{26}$/', $res->json('data.reference'));

        $order = SandboxOrder::firstOrFail();
        $this->assertSame('payment_pending', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame($res->json('data.reference'), $order->payment_reference);

        $payment = SandboxPayment::firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('xtra4u_sandbox', $payment->gateway);
        $this->assertSame('10.00', $payment->amount);
        $this->assertNoProductionServiceTouched();
    }

    public function test_a_payment_can_only_be_started_once_per_order(): void
    {
        [$w, $id, $ref] = $this->pendingOrder();

        $this->startPayment($w['key'], $id)->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_ALREADY_INITIATED')
            ->assertJsonPath('error.details.reference', $ref);

        $this->assertSame(1, SandboxPayment::count());
    }

    public function test_payment_references_are_unique_across_orders(): void
    {
        $w = $this->sandboxWorld();
        [, , $r1] = $this->pendingOrder($w, 'a');
        [, , $r2] = $this->pendingOrder($w, 'b');

        $this->assertNotSame($r1, $r2);
        $this->assertSame(2, SandboxPayment::count());
        $this->assertCount(2, array_unique(SandboxPayment::pluck('payment_ref')->all()));
    }

    public function test_a_payment_that_has_already_settled_cannot_be_restarted(): void
    {
        [$w, $id] = $this->pendingOrder();
        $this->simulate($w['key'], $id, ['event' => 'payment.failed'])->assertOk();

        $this->startPayment($w['key'], $id)->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_ALREADY_INITIATED');
        $this->assertSame(1, SandboxPayment::count());
    }

    public function test_a_payment_for_an_unknown_order_is_not_found(): void
    {
        $w = $this->sandboxWorld();

        $this->startPayment($w['key'], 'sbx_ord_'.str_repeat('a', 26))->assertNotFound();
        $this->assertSame(0, SandboxPayment::count());
    }

    // ── outcomes ─────────────────────────────────────────────────────────────

    public function test_the_payment_stays_pending_until_an_outcome_is_simulated(): void
    {
        [$w, $id, $ref] = $this->pendingOrder();

        $this->getJson("/api/v1/orders/{$id}/status", $this->auth($w['key']))
            ->assertOk()->assertJsonPath('data.status', 'payment_pending')->assertJsonPath('data.payment.status', 'pending');
        $this->getJson("/api/v1/transactions/{$ref}", $this->auth($w['key']))
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.paid_at', null);
    }

    public function test_a_successful_simulated_payment(): void
    {
        [$w, $id, $ref] = $this->pendingOrder();
        $this->forbidProductionServices();

        $this->simulate($w['key'], $id, ['event' => 'payment.succeeded'])->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.applied.0.step', 'payment.succeeded')
            ->assertJsonPath('data.applied.0.changed', true)
            ->assertJsonPath('data.order.status', 'processing')
            ->assertJsonPath('data.order.payment.status', 'paid')
            ->assertJsonPath('data.order.fulfillment.status', 'pending');

        $tx = $this->getJson("/api/v1/transactions/{$ref}", $this->auth($w['key']))->assertOk();
        $tx->assertJsonPath('data.status', 'paid')->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.environment', 'sandbox')->assertJsonPath('data.order_id', $id)
            ->assertJsonPath('data.simulation_outcome', 'succeeded')->assertJsonPath('data.gateway', 'xtra4u_sandbox');
        $this->assertNotNull($tx->json('data.paid_at'));
        $this->assertNoProductionServiceTouched();
    }

    public function test_a_failed_simulated_payment(): void
    {
        [$w, $id, $ref] = $this->pendingOrder();

        $this->simulate($w['key'], $id, ['event' => 'payment.failed'])->assertOk()
            ->assertJsonPath('data.order.status', 'failed')
            ->assertJsonPath('data.order.payment.status', 'failed')
            ->assertJsonPath('data.order.fulfillment.status', 'not_started');

        $this->getJson("/api/v1/transactions/{$ref}", $this->auth($w['key']))
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('data.simulation_outcome', 'failed');
    }

    public function test_simulating_the_same_outcome_twice_is_an_idempotent_replay(): void
    {
        [$w, $id] = $this->pendingOrder();

        $this->simulate($w['key'], $id, ['event' => 'payment.succeeded'])->assertOk()->assertJsonPath('meta.idempotent_replay', false);
        $eventsBefore = SandboxOrderEvent::count();

        $this->simulate($w['key'], $id, ['event' => 'payment.succeeded'])->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.applied.0.changed', false);

        $this->assertSame($eventsBefore, SandboxOrderEvent::count(), 'a replay must not append history or events');
    }

    public function test_contradicting_a_settled_payment_is_refused(): void
    {
        [$w, $id] = $this->pendingOrder();
        $this->simulate($w['key'], $id, ['event' => 'payment.succeeded'])->assertOk();

        $this->simulate($w['key'], $id, ['event' => 'payment.failed'])->assertStatus(409)
            ->assertJsonPath('error.code', 'SIMULATION_NOT_ALLOWED')
            ->assertJsonPath('error.details.payment_status', 'paid');
        $this->assertSame('paid', SandboxOrder::firstOrFail()->payment_status);
    }

    public function test_an_outcome_cannot_be_simulated_before_a_payment_is_started(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);

        foreach (['payment.succeeded', 'payment.failed'] as $event) {
            $this->simulate($w['key'], $id, ['event' => $event])->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_NOT_INITIATED');
        }
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_NOT_INITIATED');

        $order = SandboxOrder::firstOrFail();
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
    }

    // ── arbitrary state injection ────────────────────────────────────────────

    public function test_arbitrary_status_injection_is_rejected(): void
    {
        [$w, $id] = $this->pendingOrder();

        foreach ([
            ['status' => 'completed'],
            ['payment_status' => 'paid'],
            ['fulfillment_status' => 'completed'],
            ['amount' => 0.01],
            ['paid' => true],
            ['event' => 'payment.succeeded', 'status' => 'completed'],     // a valid event cannot smuggle a status along
            ['event' => 'payment.succeeded', 'payment_status' => 'failed'],
        ] as $body) {
            $this->simulate($w['key'], $id, $body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        $order = SandboxOrder::firstOrFail();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('payment_pending', $order->status);
    }

    public function test_only_predefined_events_and_scenarios_are_accepted(): void
    {
        [$w, $id] = $this->pendingOrder();

        foreach ([
            [],
            ['event' => 'payment.refunded'],
            ['event' => 'order.completed'],           // a webhook type is not a simulation step
            ['event' => 'PAYMENT.SUCCEEDED'],
            ['event' => ['payment.succeeded']],
            ['scenario' => 'drop_database'],
            ['event' => 'payment.succeeded', 'scenario' => 'success'],   // exactly one of the two
        ] as $body) {
            $this->simulate($w['key'], $id, $body)->assertStatus(422);
        }

        $this->assertSame('pending', SandboxOrder::firstOrFail()->payment_status);
    }

    public function test_the_list_of_simulations_is_discoverable(): void
    {
        $w = $this->sandboxWorld();

        $this->getJson('/api/v1/sandbox/simulations', $this->auth($w['key']))->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.events', ['payment.succeeded', 'payment.failed', 'fulfillment.processing', 'fulfillment.completed', 'fulfillment.failed'])
            ->assertJsonPath('data.scenarios.success', ['payment.succeeded', 'fulfillment.processing', 'fulfillment.completed']);
    }

    // ── production is never involved ─────────────────────────────────────────

    public function test_no_payment_gateway_wallet_or_production_record_is_ever_involved(): void
    {
        $w = $this->sandboxWorld(10.0);
        $this->paystack();                                   // a real, configured gateway exists...
        $this->forbidProductionServices();                   // ...and any attempt to use it is recorded and throws
        $before = $this->productionFingerprint($w['vendor']);

        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $ref = $this->startPayment($w['key'], $id)->assertStatus(202)->json('data.reference');
        $this->getJson("/api/v1/transactions/{$ref}", $this->auth($w['key']))->assertOk();
        $this->getJson("/api/v1/orders/{$id}/status", $this->auth($w['key']))->assertOk();
        $this->simulate($w['key'], $id, ['event' => 'payment.succeeded'])->assertOk();

        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
        $this->assertNoProductionServiceTouched();
    }

    public function test_the_forbidden_service_guard_actually_trips(): void
    {
        // Self-test of the instrumentation: if a sandbox flow ever resolved the gateway manager, we would know.
        $this->forbidProductionServices();

        try {
            app(GatewayManager::class);
            $this->fail('the guard did not throw');
        } catch (\LogicException) {
        }

        $this->assertSame([GatewayManager::class], $this->touched);
    }

    public function test_status_polling_in_the_sandbox_never_asks_a_gateway(): void
    {
        [$w, $id] = $this->pendingOrder();
        $this->forbidProductionServices();

        foreach (range(1, 3) as $_) {
            $this->getJson("/api/v1/orders/{$id}/status", $this->auth($w['key']))->assertOk()->assertJsonPath('data.simulated', true);
        }

        $this->assertNoProductionServiceTouched();
    }
}
