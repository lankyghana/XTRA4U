<?php

namespace Tests\Feature\DeveloperApi;

use App\Jobs\ProcessExternalFulfillment;
use App\Models\Developer\SandboxOrder;
use App\Models\Developer\SandboxOrderEvent;
use Illuminate\Support\Facades\Bus;

class SandboxFulfillmentTest extends SandboxTestCase
{
    /** A paid sandbox order, fulfilment pending. @return array{0:array,1:string} */
    private function paidOrder(): array
    {
        $w = $this->sandboxWorld(10.0);
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id)->assertStatus(202);
        $this->simulate($w['key'], $id, ['event' => 'payment.succeeded'])->assertOk();

        return [$w, $id];
    }

    public function test_after_payment_fulfillment_is_pending(): void
    {
        [$w, $id] = $this->paidOrder();

        $this->getJson("/api/v1/orders/{$id}", $this->auth($w['key']))->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.fulfillment.status', 'pending')
            ->assertJsonPath('data.fulfillment.simulated', true);
    }

    public function test_the_simulated_fulfillment_moves_to_processing_then_completed(): void
    {
        [$w, $id] = $this->paidOrder();
        $this->forbidProductionServices();

        $this->simulate($w['key'], $id, ['event' => 'fulfillment.processing'])->assertOk()
            ->assertJsonPath('data.order.status', 'processing')
            ->assertJsonPath('data.order.fulfillment.status', 'processing')
            ->assertJsonPath('data.order.fulfillment.result.status', 'processing');

        $done = $this->simulate($w['key'], $id, ['event' => 'fulfillment.completed'])->assertOk();
        $done->assertJsonPath('data.order.status', 'completed')
            ->assertJsonPath('data.order.fulfillment.status', 'completed')
            ->assertJsonPath('data.order.fulfillment.result.provider', 'xtra4u_sandbox')
            ->assertJsonPath('data.order.fulfillment.result.simulated', true);
        $this->assertStringStartsWith('sbx_ful_', $done->json('data.order.fulfillment.result.reference'));
        $this->assertNotNull($done->json('data.order.fulfillment.result.completed_at'));

        $this->getJson("/api/v1/orders/{$id}/status", $this->auth($w['key']))->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertNoProductionServiceTouched();
    }

    public function test_fulfillment_can_complete_straight_from_pending(): void
    {
        [$w, $id] = $this->paidOrder();

        $this->simulate($w['key'], $id, ['event' => 'fulfillment.completed'])->assertOk()->assertJsonPath('data.order.status', 'completed');
    }

    public function test_a_failed_simulated_fulfillment_reaches_failed_after_a_paid_order(): void
    {
        [$w, $id] = $this->paidOrder();

        $res = $this->simulate($w['key'], $id, ['event' => 'fulfillment.failed'])->assertOk();

        $res->assertJsonPath('data.order.status', 'failed')
            ->assertJsonPath('data.order.payment.status', 'paid')                 // the payment itself succeeded
            ->assertJsonPath('data.order.fulfillment.status', 'failed')
            ->assertJsonPath('data.order.fulfillment.result.failure_reason', 'SIMULATED_FULFILLMENT_FAILURE');
        $this->assertNotNull($res->json('data.order.fulfillment.result.failed_at'));
    }

    public function test_fulfillment_cannot_be_simulated_before_the_payment_has_succeeded(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id)->assertStatus(202);   // payment still pending

        foreach (['fulfillment.processing', 'fulfillment.completed', 'fulfillment.failed'] as $event) {
            $this->simulate($w['key'], $id, ['event' => $event])->assertStatus(409)->assertJsonPath('error.code', 'SIMULATION_NOT_ALLOWED');
        }

        $this->assertSame('not_started', SandboxOrder::firstOrFail()->fulfillment_status);
    }

    public function test_fulfillment_cannot_be_simulated_after_a_failed_payment(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);
        $this->simulate($w['key'], $id, ['event' => 'payment.failed'])->assertOk();

        $this->simulate($w['key'], $id, ['event' => 'fulfillment.completed'])->assertStatus(409)->assertJsonPath('error.code', 'SIMULATION_NOT_ALLOWED');
        $this->assertSame('failed', SandboxOrder::firstOrFail()->status);
    }

    public function test_terminal_states_cannot_be_contradicted_but_replays_are_harmless(): void
    {
        [$w, $id] = $this->paidOrder();
        $this->simulate($w['key'], $id, ['event' => 'fulfillment.completed'])->assertOk();

        $this->simulate($w['key'], $id, ['event' => 'fulfillment.failed'])->assertStatus(409)->assertJsonPath('error.code', 'SIMULATION_NOT_ALLOWED');
        $this->simulate($w['key'], $id, ['event' => 'fulfillment.processing'])->assertStatus(409);
        $this->simulate($w['key'], $id, ['event' => 'fulfillment.completed'])->assertOk()->assertJsonPath('meta.idempotent_replay', true);
        $this->assertSame('completed', SandboxOrder::firstOrFail()->status);
    }

    // ── scenarios ────────────────────────────────────────────────────────────

    public function test_the_success_scenario_drives_a_paid_order_to_completed(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);

        $res = $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk();

        $this->assertSame(['payment.succeeded', 'fulfillment.processing', 'fulfillment.completed'], array_column($res->json('data.applied'), 'step'));
        $res->assertJsonPath('data.order.status', 'completed');
        $this->assertSame(5, SandboxOrderEvent::count()); // created, payment pending, then the three scenario steps
    }

    public function test_the_payment_failed_scenario(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);

        $this->simulate($w['key'], $id, ['scenario' => 'payment_failed'])->assertOk()
            ->assertJsonPath('data.order.status', 'failed')->assertJsonPath('data.order.payment.status', 'failed');
    }

    public function test_the_fulfillment_failed_scenario(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);

        $this->simulate($w['key'], $id, ['scenario' => 'fulfillment_failed'])->assertOk()
            ->assertJsonPath('data.order.status', 'failed')
            ->assertJsonPath('data.order.payment.status', 'paid')
            ->assertJsonPath('data.order.fulfillment.status', 'failed');
    }

    public function test_a_scenario_is_all_or_nothing(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);
        $this->simulate($w['key'], $id, ['event' => 'payment.failed'])->assertOk();
        $historyBefore = SandboxOrderEvent::count();

        // payment already failed: step 1 of the success scenario contradicts it, so nothing at all is applied.
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertStatus(409);

        $this->assertSame($historyBefore, SandboxOrderEvent::count());
        $this->assertSame('failed', SandboxOrder::firstOrFail()->payment_status);
    }

    public function test_a_scenario_can_finish_an_order_that_is_part_way_through(): void
    {
        [$w, $id] = $this->paidOrder();
        $this->simulate($w['key'], $id, ['event' => 'fulfillment.processing'])->assertOk();

        $res = $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk();

        $this->assertSame([false, false, true], array_column($res->json('data.applied'), 'changed'));
        $res->assertJsonPath('data.order.status', 'completed');
    }

    // ── history & isolation ──────────────────────────────────────────────────

    public function test_every_transition_is_recorded_in_the_order_history(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);
        $this->simulate($w['key'], $id, ['scenario' => 'success']);

        $rows = SandboxOrderEvent::orderBy('id')->get();
        $this->assertSame(
            ['order.created', 'payment.pending', 'payment.succeeded', 'fulfillment.processing', 'fulfillment.completed'],
            $rows->pluck('type')->all()
        );
        $this->assertSame([null, 'awaiting_payment', 'payment_pending', 'processing', 'processing'], $rows->pluck('from_status')->all());
        $this->assertSame(['awaiting_payment', 'payment_pending', 'processing', 'processing', 'completed'], $rows->pluck('to_status')->all());
        $this->assertCount(5, $rows->pluck('event_ref')->unique());
        $this->assertTrue($rows->every(fn ($r) => str_starts_with($r->event_ref, 'sbe_')));
    }

    public function test_external_fulfillment_wallet_and_commission_are_never_touched(): void
    {
        $w = $this->sandboxWorld(10.0);
        $this->forbidProductionServices();
        Bus::fake();
        $before = $this->productionFingerprint($w['vendor']);

        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk();
        $other = $this->sandboxOrderId($w['key'], $w['offer'], 'k2');
        $this->startPayment($w['key'], $other);
        $this->simulate($w['key'], $other, ['scenario' => 'fulfillment_failed'])->assertOk();

        Bus::assertNotDispatched(ProcessExternalFulfillment::class);
        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
        $this->assertSame((string) $w['vendor']->fresh()->wallet_balance, (string) $before['vendors'][$w['vendor']->id]['wallet_balance']);
        $this->assertNoProductionServiceTouched();
    }
}
