<?php

namespace Tests\Feature\UtilityBills;

use App\Models\User;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillCommissionService;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillPipeline;
use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Automatic status polling is bounded: progressive backoff, then provider_unresolved, which is
 * never a failure and stays fully recoverable through an admin refresh of the SAME provider order.
 */
class UtilityBillStatusPollingTest extends UtilityBillTestCase
{
    private function statusCalls(): int
    {
        return Http::recorded(fn (Request $q) => str_contains($q->url(), '/utilities/orders/'))->count();
    }

    /** Each submission gets its own provider reference; status answers echo the queried one. */
    private function fakeProvider(string $status = 'pending', $commission = null): void
    {
        $this->fake([
            self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference'])),
            self::BASE.'/utilities/orders/*' => fn (Request $q) => Http::response($this->statusBody($status, basename($q->url()), $commission)),
        ]);
    }

    /** Pay submissions the provider accepted (survives re-faking, unlike Http::recorded()). */
    private function providerAccepted(): int
    {
        return UtilityBillEvent::query()->where('kind', 'provider_accepted')->count();
    }

    /** Submitted to the provider (pending), vendor-attributed with a 1% (GHS 1.00) frozen commission. */
    private function submitted(): UtilityBillOrder
    {
        $this->fakeProvider();
        $u = $this->makeOrder(['paid' => true, 'phone' => '0551617309'])->fresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);

        return $u;
    }

    /** Run the sweeper each time the order next becomes due, the way the per-minute scheduler would. */
    private function pollUntilStopped(UtilityBillOrder $u, int $maxRuns = 200): array
    {
        $intervals = [];
        for ($i = 0; $i < $maxRuns; $i++) {
            $u->refresh();
            if ($u->next_status_check_at === null) {
                break;
            }
            if (app(UtilityBillFulfillmentService::class)->pollingExhausted($u)) {
                $this->travel(1)->minutes();                   // the next per-minute scheduler run
                app(UtilityBillSweeper::class)->run();

                continue;
            }
            $intervals[] = (int) round($u->last_status_check_at
                ? $u->last_status_check_at->diffInSeconds($u->next_status_check_at)
                : $u->submitted_at->diffInSeconds($u->next_status_check_at));
            $this->travelTo($u->next_status_check_at->copy()->addSecond());
            app(UtilityBillSweeper::class)->run();
        }

        return $intervals;
    }

    public function test_polling_backs_off_progressively_and_stops_at_the_check_budget(): void
    {
        config(['utility_bills.status_poll_max_checks' => 12, 'utility_bills.status_poll_max_hours' => 1000]);
        $u = $this->submitted();

        $intervals = $this->pollUntilStopped($u);

        // Frequent right after submission, then progressively rarer, ending hourly.
        $this->assertSame([20, 45, 90, 180, 300, 600, 1200, 1800, 3600, 3600, 3600, 3600], $intervals);
        $this->assertSame(12, $this->statusCalls());

        $u->refresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $u->fulfillment_status);
        $this->assertNull($u->next_status_check_at);

        // Routine polling has stopped for good: days of scheduler runs send nothing more.
        foreach (range(1, 48) as $h) {
            $this->travel(1)->hours();
            app(UtilityBillSweeper::class)->run();
        }
        $this->assertSame(12, $this->statusCalls());
    }

    public function test_the_default_horizon_is_about_a_day_and_time_alone_also_stops_polling(): void
    {
        $u = $this->submitted();
        $start = Carbon::now();
        $this->pollUntilStopped($u);

        $u->refresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $u->fulfillment_status);
        $this->assertSame(30, $this->statusCalls());
        $hours = $start->diffInHours(Carbon::now(), true);
        $this->assertGreaterThan(20, $hours);
        $this->assertLessThanOrEqual(25, $hours);

        // Count budget not reached, but the time horizon is: stopped too.
        config(['utility_bills.status_poll_max_checks' => 1000, 'utility_bills.status_poll_max_hours' => 2]);
        $other = $this->submitted();
        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        $calls = $this->statusCalls();
        app(UtilityBillSweeper::class)->run();
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $other->fresh()->fulfillment_status);
        $this->assertSame($calls, $this->statusCalls(), 'stopped before polling again');
    }

    public function test_unresolved_is_not_a_failure_and_keeps_payment_references_history_and_commission_terms(): void
    {
        config(['utility_bills.status_poll_max_checks' => 3]);
        $u = $this->submitted();
        $before = $u->only(['vendor_id', 'provider_request_reference', 'provider_order_reference', 'provider_attempt', 'commission_type',
            'commission_value', 'commission_amount', 'commission_status', 'bill_amount', 'expected_amount']);
        $this->pollUntilStopped($u);
        $eventsBefore = $u->events()->pluck('id');

        $u->refresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $u->fulfillment_status);
        $this->assertSame($before, $u->only(array_keys($before)));
        $this->assertSame('pending', $u->provider_status);
        $this->assertSame('paid', $u->order->payment_status);
        $this->assertSame('pending', $u->commission_status);
        $this->assertSame(1, $u->events()->where('kind', 'status_polling_stopped')->count());
        $this->assertTrue($eventsBefore->every(fn ($id) => $u->events()->whereKey($id)->exists()), 'history is append-only');
        $this->assertSame(0, DB::table('wallet_ledgers')->count());

        // Customer: payment received, delayed; never "failed".
        $this->getJson(route('utility-bills.poll', $u->access_token))
            ->assertJson(['stage' => 'delayed', 'headline' => 'Payment received']);

        // Admin: visible in its own filter and in the pipeline; never re-sent.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.utility-bill-sales.index', ['fulfillment' => FulfillmentStatus::PROVIDER_UNRESOLVED]))->assertOk()->assertSee($u->public_ref);
        $this->get(route('admin.utility-bill-sales.show', $u))->assertOk()->assertSee('Provider status unresolved')->assertSee('Refresh status')->assertSee('Resume automatic checks');
        $this->assertSame(1, app(UtilityBillPipeline::class)->snapshot()['unresolved']);
        $this->post(route('admin.utility-bill-sales.retry', $u))->assertSessionHas('error');
        $this->assertSame(1, Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'))->count());
    }

    public function test_admin_refresh_queries_the_existing_provider_order_and_a_late_completion_pays_commission_exactly_once(): void
    {
        config(['utility_bills.status_poll_max_checks' => 2]);
        $u = $this->submitted();
        $vendor = Vendor::query()->findOrFail($u->vendor_id);
        $this->pollUntilStopped($u);
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $u->fresh()->fulfillment_status);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        // Still pending at the provider: recorded, stays unresolved, no automatic polling restarts.
        $this->post(route('admin.utility-bill-sales.refresh', $u))->assertSessionHas('success');
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $u->fresh()->fulfillment_status);
        $this->assertNull($u->fresh()->next_status_check_at);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/utilities/orders/'.$u->provider_order_reference));

        // KiNG FLEXY finally completes it.
        $this->fakeProvider('completed', 1.3);
        $this->post(route('admin.utility-bill-sales.refresh', $u))->assertSessionHas('success', 'Status refreshed from the provider: Completed.');

        $u->refresh();
        $this->assertSame(FulfillmentStatus::COMPLETED, $u->fulfillment_status);
        $this->assertSame('credited', $u->commission_status);
        $this->assertSame('1.00', (string) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, DB::table('wallet_ledgers')->count());
        $this->getJson(route('utility-bills.poll', $u->access_token))->assertJson(['stage' => 'completed', 'headline' => 'Utility bill payment completed']);

        // Nothing can credit it again.
        $this->post(route('admin.utility-bill-sales.refresh', $u))->assertSessionHas('error');
        app(UtilityBillFulfillmentService::class)->afterStatusChange($u->id);
        $this->assertSame(UtilityBillCommissionService::ALREADY, app(UtilityBillCommissionService::class)->settle($u->id));
        $this->assertSame('1.00', (string) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, DB::table('wallet_ledgers')->count());
        $this->assertSame(1, $this->providerAccepted(), 'the bill was sent to the provider once');
    }

    public function test_a_late_refund_keeps_the_controlled_new_attempt_rules_and_failed_never_permits_one(): void
    {
        config(['utility_bills.status_poll_max_checks' => 1]);
        $svc = app(UtilityBillFulfillmentService::class);
        $actor = ['id' => 1, 'email' => 'a@x'];

        // refunded: a new attempt needs a reason and a live provider confirmation.
        $refunded = $this->submitted();
        $this->pollUntilStopped($refunded);
        $this->fakeProvider('refunded');
        $this->assertSame('updated', $svc->syncStatus($refunded->id, 'admin:1'));
        $this->assertSame(FulfillmentStatus::PROVIDER_REFUNDED, $refunded->fresh()->fulfillment_status);
        $this->assertFalse($svc->adminRetry($refunded->id, $actor, newAttempt: true, reason: '')['ok']);
        $this->assertTrue($svc->adminRetry($refunded->id, $actor, newAttempt: true, reason: 'Provider refunded; customer still needs it')['ok']);
        $refunded->refresh();
        $this->assertSame(2, (int) $refunded->provider_attempt);
        $closed = $refunded->events()->where('kind', 'attempt_closed')->firstOrFail();
        $this->assertSame('UTIL-XU-'.$refunded->public_ref.'-A1', $closed->meta['provider_order_reference']);
        $this->assertSame('XU-'.$refunded->public_ref.'-A2', $refunded->provider_request_reference);

        // failed: recorded, but never grounds for another provider attempt.
        $this->fakeProvider();
        $failed = $this->submitted();
        $this->pollUntilStopped($failed);
        $this->fakeProvider('failed');
        $this->assertSame('updated', $svc->syncStatus($failed->id, 'admin:1'));
        $this->assertSame(FulfillmentStatus::FAILED, $failed->fresh()->fulfillment_status);
        $this->assertFalse($svc->adminRetry($failed->id, $actor)['ok']);
        $this->assertFalse($svc->adminRetry($failed->id, $actor, newAttempt: true, reason: 'Trying again anyway')['ok']);
        $this->assertSame(1, (int) $failed->fresh()->provider_attempt);
    }

    public function test_admin_can_resume_one_bounded_window_of_automatic_checks(): void
    {
        config(['utility_bills.status_poll_max_checks' => 2]);
        $u = $this->submitted();
        $this->pollUntilStopped($u);
        $calls = $this->statusCalls();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('admin.utility-bill-sales.resume-polling', $u))->assertSessionHas('success');

        $u->refresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);
        $this->assertSame(0, (int) $u->status_check_attempts);
        $this->assertSame(1, $u->events()->where('kind', 'admin_resume_polling')->count());

        $this->pollUntilStopped($u);
        $this->assertSame($calls + 2, $this->statusCalls());
        $this->assertSame(FulfillmentStatus::PROVIDER_UNRESOLVED, $u->fresh()->fulfillment_status);

        // Only an unresolved order can be resumed.
        $this->post(route('admin.utility-bill-sales.resume-polling', $this->submitted()))->assertSessionHas('error');
    }

    public function test_the_status_job_frees_its_slot_so_early_backoff_steps_are_honoured(): void
    {
        $u = $this->submitted();
        $this->travel(21)->seconds();
        app(UtilityBillSweeper::class)->run();
        $this->travel(46)->seconds();          // 45 s later: well inside the old 2-minute marker
        app(UtilityBillSweeper::class)->run();

        $this->assertSame(2, $this->statusCalls());
        $this->assertSame(2, (int) $u->fresh()->status_check_attempts);
    }
}
