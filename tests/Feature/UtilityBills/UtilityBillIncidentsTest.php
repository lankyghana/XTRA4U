<?php

namespace Tests\Feature\UtilityBills;

use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\User;
use App\Models\UtilityBillIncident;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillIncidents;
use App\Services\UtilityBills\UtilityBillPipeline;
use App\Services\UtilityBills\UtilityBillSweeper;
use App\Support\PaymentIntegrity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A provider outage produces ONE actionable incident alert (plus bounded reminders and one
 * recovery notice), not one alert per stuck order; orders keep their own state and history.
 */
class UtilityBillIncidentsTest extends UtilityBillTestCase
{
    private function incidentAlerts(): Collection
    {
        return AdminNotification::query()->where('type', 'utility_bill_incident')->orderBy('id')->pluck('title');
    }

    private function perOrderAlerts(): int
    {
        return AdminNotification::query()->where('type', 'utility_bill_attention')->count();
    }

    /** Paid orders left queued (no immediate dispatch), as a backlog during an outage would be. */
    private function paidQueued(int $n, array $o = []): Collection
    {
        $real = Queue::getFacadeRoot();
        Queue::fake();
        $orders = collect(range(1, $n))->map(fn () => $this->makeOrder(['paid' => true, 'vendor' => null] + $o));
        Queue::swap($real);                    // later dispatches run synchronously again

        return $orders;
    }

    private function sweepEvery(int $minutes, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->travel($minutes)->minutes();
            app(UtilityBillSweeper::class)->run();
        }
    }

    public function test_an_outage_affecting_fifty_paid_orders_raises_one_alert_and_every_order_keeps_its_own_state(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 1000, 'utility_bills.incident_realert_minutes' => 100000]);
        $orders = $this->paidQueued(50);
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('timed out')]);

        // Every order retries until its automatic attempts are exhausted.
        $this->sweepEvery(16, 10);

        $this->assertSame(['Utility Bills incident: KiNG FLEXY not responding (timeouts)'], $this->incidentAlerts()->all());
        $this->assertSame(0, $this->perOrderAlerts(), 'no per-order notification spam');

        $incident = UtilityBillIncident::query()->where('category', 'provider_unreachable')->sole();
        $this->assertTrue($incident->isActive());
        $this->assertSame(50, $incident->affected_orders);
        $this->assertGreaterThanOrEqual(50 * 8, $incident->occurrences);

        foreach ($orders as $u) {
            $u->refresh();
            $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);
            $this->assertSame('max_attempts', $u->last_error_code);
            $this->assertSame('XU-'.$u->public_ref.'-A1', $u->provider_request_reference);
            $this->assertSame(8, $u->events()->where('kind', 'submit_claimed')->count(), 'full per-order audit trail');
            $this->assertSame('paid', $u->order->payment_status);
        }

        // Admin sees an outage (not a capacity backlog) and can inspect the affected orders.
        $this->assertSame('outage', app(UtilityBillPipeline::class)->snapshot()['state']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.utility-bill-sales.index'))->assertOk()->assertSee('Provider outage')->assertSee('KiNG FLEXY not responding (timeouts)');
        $this->get(route('admin.utility-bill-incidents.index'))->assertOk()->assertSee('KiNG FLEXY not responding (timeouts)')->assertSee('50');
        $this->get(route('admin.utility-bill-incidents.show', $incident))->assertOk()
            ->assertSee($orders->first()->public_ref)->assertSee('Retries exhausted')->assertSee('Oldest affected order');
        $this->get(route('admin.utility-bill-sales.show', $orders->first()))->assertOk()->assertSee('Affected by:');
    }

    public function test_recovery_closes_the_incident_once_and_requeues_parked_orders_with_their_same_reference(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 1000, 'utility_bills.incident_realert_minutes' => 100000]);
        $orders = $this->paidQueued(6);
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('timed out')]);
        $this->sweepEvery(16, 10);
        $this->assertTrue($orders->every(fn ($u) => $u->fresh()->last_error_code === 'max_attempts'));

        // KiNG FLEXY is back: the next payment succeeds.
        $this->fake([self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference']))]);
        $fresh = $this->makeOrder(['paid' => true, 'vendor' => null]);
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $fresh->fresh()->fulfillment_status);

        $incident = UtilityBillIncident::query()->where('category', 'provider_unreachable')->sole();
        $this->assertFalse($incident->isActive());
        $this->assertSame('a provider payment succeeded', $incident->recovery_note);
        $this->assertSame('Recovered: KiNG FLEXY not responding (timeouts)', $this->incidentAlerts()->last());
        $this->assertCount(2, $this->incidentAlerts());

        foreach ($orders as $u) {
            $u->refresh();
            $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
            $this->assertSame(0, (int) $u->submit_attempts);
            $this->assertSame(1, $u->events()->where('kind', 'auto_requeue_after_recovery')->count());
        }

        // The sweeper then submits them, oldest first, with the SAME request references.
        app(UtilityBillSweeper::class)->run();
        $sent = Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'))->map(fn ($p) => $p[0]['reference'])->values()->all();
        $this->assertSame($orders->map(fn ($u) => 'XU-'.$u->public_ref.'-A1')->all(), array_slice($sent, 1));
        $this->assertTrue($orders->every(fn ($u) => $u->fresh()->fulfillment_status === FulfillmentStatus::PROVIDER_PENDING));
    }

    public function test_a_long_outage_sends_a_bounded_reminder_not_an_alert_per_order(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 1000, 'utility_bills.incident_realert_minutes' => 60]);
        $this->paidQueued(20);
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('timed out')]);

        $this->sweepEvery(16, 10);    // ~160 minutes of outage

        $alerts = $this->incidentAlerts();
        $this->assertSame('Utility Bills incident: KiNG FLEXY not responding (timeouts)', $alerts->first());
        $this->assertGreaterThanOrEqual(1, $alerts->filter(fn ($t) => str_starts_with($t, 'Still ongoing:'))->count());
        $this->assertLessThanOrEqual(3, $alerts->count(), 'at most one reminder per hour, regardless of 20 orders x 8 retries');
    }

    public function test_genuinely_different_issues_open_separate_incidents(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 100]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Unauthorized'], 401)]);
        $this->makeOrder(['paid' => true, 'vendor' => null]);
        $this->makeOrder(['paid' => true, 'vendor' => null]);

        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Biller disabled'], 503)]);
        $this->makeOrder(['paid' => true, 'vendor' => null, 'biller' => 'dstv']);
        $this->makeOrder(['paid' => true, 'vendor' => null, 'biller' => 'gotv']);
        $this->makeOrder(['paid' => true, 'vendor' => null, 'biller' => 'gotv']);

        $incidents = UtilityBillIncident::query()->orderBy('id')->get();
        $this->assertSame(['provider_auth', 'service_disabled', 'service_disabled'], $incidents->pluck('category')->all());
        $this->assertSame(['', 'dstv', 'gotv'], $incidents->pluck('scope')->all());
        $this->assertSame([2, 1, 2], $incidents->pluck('affected_orders')->all());
        $this->assertCount(3, $this->incidentAlerts());
        $this->assertSame(0, $this->perOrderAlerts());

        // A success for dstv closes only the dstv incident.
        $this->fake([self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference']))]);
        $this->makeOrder(['paid' => true, 'vendor' => null, 'biller' => 'dstv']);
        $this->assertSame([false, false, true], UtilityBillIncident::query()->orderBy('id')->get()->map->isActive()->all());
    }

    public function test_order_specific_and_payment_integrity_problems_are_never_grouped_into_a_provider_incident(): void
    {
        // Provider rejected THIS bill (400): its own alert.
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Invalid account'], 400)]);
        $rejected = $this->makeOrder(['paid' => true, 'vendor' => null]);
        $this->assertSame(FulfillmentStatus::ATTENTION, $rejected->fresh()->fulfillment_status);

        // Payment no longer confirmed for fulfillment: its own alert, nothing sent.
        $paid = $this->paidQueued(1)->first();
        Order::query()->whereKey($paid->order_id)->update(['payment_integrity_status' => PaymentIntegrity::MISMATCH]);
        $this->assertSame('skipped:not_paid', app(UtilityBillFulfillmentService::class)->submit($paid->id));

        $this->assertSame(0, UtilityBillIncident::query()->count());
        $this->assertSame(2, $this->perOrderAlerts());
        $this->assertSame('payment_unconfirmed', $paid->fresh()->last_error_code);
    }

    public function test_slow_orders_are_grouped_into_one_delayed_incident_that_recovers_only_when_all_finish(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference'])),
            self::BASE.'/utilities/orders/*' => fn (Request $q) => Http::response($this->statusBody('processing', basename($q->url())))]);
        $orders = collect(range(1, 5))->map(fn () => $this->makeOrder(['paid' => true, 'vendor' => null]));

        $this->travel(121)->minutes();
        app(UtilityBillSweeper::class)->run();
        app(UtilityBillSweeper::class)->run();

        $this->assertSame(1, AdminNotification::query()->where('title', 'Utility Bill still processing')->count());
        $delayed = UtilityBillIncident::query()->where('category', 'delayed_orders')->sole();
        $this->assertSame(5, $delayed->affected_orders);
        $this->assertTrue($orders->every(fn ($u) => $u->fresh()->stuck_alerted_at !== null), 'each order still records its own alert marker');

        // Four complete; one is still unfinished, so the incident stays open.
        $this->fake([self::BASE.'/utilities/orders/*' => fn (Request $q) => Http::response($this->statusBody('completed', basename($q->url())))]);
        foreach ($orders->take(4) as $u) {
            app(UtilityBillFulfillmentService::class)->syncStatus($u->id, 'admin:1');
        }
        app(UtilityBillSweeper::class)->run();
        $this->assertTrue($delayed->fresh()->isActive());

        app(UtilityBillFulfillmentService::class)->syncStatus($orders->last()->id, 'admin:1');
        app(UtilityBillSweeper::class)->run();
        $this->assertFalse($delayed->fresh()->isActive());
        $this->assertSame(1, $this->incidentAlerts()->filter(fn ($t) => $t === 'Recovered: Paid bills not finished in time')->count());
    }

    public function test_a_delay_already_explained_by_an_alerted_outage_is_grouped_silently(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference'])),
            self::BASE.'/utilities/orders/*' => fn () => throw new ConnectionException('timed out')]);
        $orders = collect(range(1, 5))->map(fn () => $this->makeOrder(['paid' => true, 'vendor' => null]));

        $this->travel(121)->minutes();
        app(UtilityBillSweeper::class)->run();

        // Status checks failing for all five: ONE status incident alert, and the delay alert is
        // not repeated on top of it (the orders are still recorded against the delayed incident).
        $this->assertSame(['Utility Bills incident: KiNG FLEXY status checks failing'], $this->incidentAlerts()->all());
        $this->assertSame(5, UtilityBillIncident::query()->where('category', 'delayed_orders')->sole()->affected_orders);
        $this->assertSame(0, $this->perOrderAlerts());

        // The status endpoint answers again: that incident recovers.
        $this->fake([self::BASE.'/utilities/orders/*' => fn (Request $q) => Http::response($this->statusBody('completed', basename($q->url())))]);
        foreach ($orders as $u) {
            app(UtilityBillFulfillmentService::class)->syncStatus($u->id, 'admin:1');
        }
        app(UtilityBillSweeper::class)->run();
        $this->assertFalse(UtilityBillIncident::query()->where('category', 'status_sync_unavailable')->sole()->isActive());
        $this->assertFalse(UtilityBillIncident::query()->where('category', 'delayed_orders')->sole()->isActive());
    }

    public function test_orders_reaching_the_polling_horizon_share_one_unresolved_incident(): void
    {
        config(['utility_bills.status_poll_max_checks' => 1]);
        $this->fake([self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference'])),
            self::BASE.'/utilities/orders/*' => fn (Request $q) => Http::response($this->statusBody('pending', basename($q->url())))]);
        $orders = collect(range(1, 4))->map(fn () => $this->makeOrder(['paid' => true, 'vendor' => null]));

        $this->sweepEvery(1, 3);

        $this->assertTrue($orders->every(fn ($u) => $u->fresh()->fulfillment_status === FulfillmentStatus::PROVIDER_UNRESOLVED));
        $this->assertSame(1, AdminNotification::query()->where('title', 'Utility Bill provider status unresolved')->count());
        $this->assertSame(4, UtilityBillIncident::query()->where('category', 'status_unresolved')->sole()->affected_orders);
    }

    public function test_an_empty_provider_wallet_keeps_its_single_pause_alert_and_tracks_the_incident(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400)]);
        $this->makeOrder(['paid' => true, 'vendor' => null]);
        $this->makeOrder(['paid' => true, 'vendor' => null]);

        $this->assertSame(1, AdminNotification::query()->where('title', 'like', 'KiNG FLEXY wallet too low%')->count());
        $this->assertCount(0, $this->incidentAlerts(), 'no duplicate of the pause alert');
        $wallet = UtilityBillIncident::query()->where('category', 'provider_wallet_low')->sole();
        $this->assertSame(2, $wallet->affected_orders);
        $this->assertSame('outage', app(UtilityBillPipeline::class)->snapshot()['state']);

        app(UtilityBillFulfillmentService::class)->resumeAfterProviderWallet('admin:1');
        $this->assertFalse($wallet->fresh()->isActive());
    }

    public function test_incident_pages_are_admin_only(): void
    {
        $incident = app(UtilityBillIncidents::class)->record('provider_auth', null, 'key rejected');

        $this->get(route('admin.utility-bill-incidents.index'))->assertRedirect();
        $this->get(route('admin.utility-bill-incidents.show', $incident))->assertRedirect();

        $this->actingAs(User::factory()->create(['role' => 'vendor']));
        $this->get(route('admin.utility-bill-incidents.index'))->assertStatus(403);
    }
}
