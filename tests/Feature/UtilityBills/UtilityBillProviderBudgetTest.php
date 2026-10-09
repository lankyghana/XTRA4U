<?php

namespace Tests\Feature\UtilityBills;

use App\Models\User;
use App\Models\UtilityBillOrder;
use App\Services\PaymentService;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\ProviderRateBudget;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillPipeline;
use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The shared provider pay budget: a sliding window no caller can bypass, oldest-first
 * draining of a backlog, and the admin's view of capacity versus backlog.
 * (Cross-process atomicity is covered against MySQL in UtilityBillMySqlConcurrencyTest.)
 */
class UtilityBillProviderBudgetTest extends UtilityBillTestCase
{
    private function payCalls(): Collection
    {
        return Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'))->map(fn ($pair) => $pair[0]['reference']);
    }

    private function fakeUniquePay(): void
    {
        $this->fake([
            self::BASE.'/utilities/pay' => fn (Request $q) => Http::response($this->payBody('UTIL-'.$q['reference'])),
            self::BASE.'/utilities/orders/*' => fn (Request $q) => Http::response($this->statusBody('pending', basename($q->url()))),
        ]);
    }

    public function test_the_budget_is_a_sliding_window_that_never_exceeds_the_limit_in_any_rolling_minute(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 5]);
        $budget = app(ProviderRateBudget::class);
        $start = now()->startOfMinute();

        // Five calls late in one clock minute...
        $this->travelTo($start->copy()->addSeconds(50));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(0, $budget->acquire('pay'));
        }

        // ...do NOT reset at the minute boundary (a fixed window would allow five more here).
        $this->travelTo($start->copy()->addSeconds(61));
        $this->assertGreaterThan(0, $budget->acquire('pay'));
        $this->assertSame(49, $budget->availableIn('pay'));

        // A slot frees exactly when the oldest call leaves the rolling minute.
        $this->travelTo($start->copy()->addSeconds(110)->addMilliseconds(500));
        $this->assertSame(0, $budget->acquire('pay'));

        // Long run with callers trying every 3 s: every rolling 60 s holds at most 5 calls, and
        // the capacity is still fully used (about 5 per minute, not half of it).
        $budget->clear('pay');
        $granted = [];
        for ($t = 0; $t < 600; $t += 3) {
            $this->travelTo($start->copy()->addMinutes(10)->addSeconds($t));
            if ($budget->acquire('pay') === 0) {
                $granted[] = $t;
            }
        }
        foreach ($granted as $from) {
            $this->assertLessThanOrEqual(5, count(array_filter($granted, fn ($g) => $g >= $from && $g < $from + 60)));
        }
        $this->assertGreaterThanOrEqual(48, count($granted));
    }

    public function test_capacity_follows_configuration_and_a_disabled_endpoint_sends_nothing(): void
    {
        $budget = app(ProviderRateBudget::class);

        config(['utility_bills.rate.pay_per_minute' => 8]);
        $this->useUpBudget('pay');
        $this->assertSame(8, $budget->used('pay'));

        config(['utility_bills.rate.status_per_minute' => 0]);
        $this->assertGreaterThan(0, $budget->acquire('status'));
        $this->assertSame(0, $budget->used('status'));
    }

    public function test_every_pay_and_status_path_draws_on_the_same_shared_budget(): void
    {
        $this->fakeUniquePay();
        $this->useUpBudget('pay');

        // Immediate dispatch after payment: held back, nothing sent, nothing counted.
        $fresh = $this->makeOrder(['paid' => true, 'vendor' => null]);
        $this->assertSame(FulfillmentStatus::QUEUED, $fresh->fresh()->fulfillment_status);

        // Admin retry of an attention order: same budget.
        $attention = $this->makeOrder(['paid' => true, 'vendor' => null]);
        UtilityBillOrder::whereKey($attention->id)->update(['fulfillment_status' => FulfillmentStatus::ATTENTION, 'last_error_code' => 'auth']);
        $this->assertTrue(app(UtilityBillFulfillmentService::class)->adminRetry($attention->id, ['id' => 1, 'email' => 'a@x'])['ok']);

        // Scheduler sweep: same budget.
        app(UtilityBillSweeper::class)->run();

        $this->assertCount(0, $this->payCalls());
        foreach ([$fresh, $attention] as $u) {
            $u->refresh();
            $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
            $this->assertSame(0, (int) $u->submit_attempts);
            $this->assertNotNull($u->provider_request_reference === null ? 'ok' : $u->provider_request_reference);
        }

        // Admin status refresh: the shared STATUS budget.
        $polled = $this->makeOrder(['paid' => true, 'vendor' => null]);
        UtilityBillOrder::whereKey($polled->id)->update(['fulfillment_status' => FulfillmentStatus::PROVIDER_PENDING, 'provider_order_reference' => 'UTIL-X-1']);
        $this->useUpBudget('status');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('admin.utility-bill-sales.refresh', $polled))->assertSessionHas('error', 'Provider rate limit reached; try again in a minute.');
        $this->assertCount(0, Http::recorded(fn (Request $q) => str_contains($q->url(), '/utilities/orders/')));
    }

    public function test_a_budget_refusal_after_the_claim_keeps_the_order_in_line_and_uncounted(): void
    {
        $this->fakeUniquePay();
        $u = $this->makeOrder(['vendor' => null]);
        Queue::fake();
        $this->prove($u->order);
        app(PaymentService::class)->completeOrder($u->order->fresh());

        // Another process takes the last slot between our pre-check and our send.
        $this->app->instance(ProviderRateBudget::class, new class extends ProviderRateBudget
        {
            public function availableIn(string $endpoint): int
            {
                return 0;
            }

            public function acquire(string $endpoint): int
            {
                return 30;
            }
        });

        $this->assertSame('requeued', app(UtilityBillFulfillmentService::class)->submit($u->id));

        $u->refresh();
        $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
        $this->assertSame(0, (int) $u->submit_attempts);
        $this->assertFalse($u->next_submit_at->isFuture(), 'stays due, keeping its place in the oldest-first line');
        $this->assertSame('XU-'.$u->public_ref.'-A1', $u->provider_request_reference);
        $this->assertCount(0, $this->payCalls());
    }

    public function test_a_backlog_drains_oldest_first_at_exactly_the_configured_rate(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 2]);
        $this->fakeUniquePay();
        $start = now()->startOfMinute()->addSeconds(5);
        $this->travelTo($start);

        $orders = collect(range(1, 7))->map(fn () => $this->makeOrder(['paid' => true, 'vendor' => null]));
        $sentPerMinute = [count($this->payCalls())];

        for ($minute = 1; $minute <= 4; $minute++) {
            $this->travelTo($start->copy()->addMinutes($minute)->addSecond());
            $before = count($this->payCalls());
            app(UtilityBillSweeper::class)->run();
            $sentPerMinute[] = count($this->payCalls()) - $before;
        }

        $this->assertSame([2, 2, 2, 1, 0], $sentPerMinute);
        $this->assertSame(
            $orders->map(fn ($u) => 'XU-'.$u->public_ref.'-A1')->all(),
            $this->payCalls()->values()->all(),
            'paid orders reach the provider in the order customers paid'
        );
        $this->assertTrue($orders->every(fn ($u) => $u->fresh()->fulfillment_status === FulfillmentStatus::PROVIDER_PENDING));
        $this->assertTrue($orders->every(fn ($u) => (int) $u->fresh()->submit_attempts === 1));
    }

    public function test_a_worker_waits_briefly_for_a_slot_that_is_about_to_free(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 1, 'utility_bills.pay_inline_wait_seconds' => 3]);
        $this->fakeUniquePay();
        $u = $this->makeOrder(['vendor' => null]);
        Queue::fake();
        $this->prove($u->order);
        app(PaymentService::class)->completeOrder($u->order->fresh());

        // The only slot was taken 59 s ago: it frees in about a second.
        Cache::put('utility_bills.budget.pay', [now()->getPreciseTimestamp(6) / 1_000_000 - 59.2], 65);

        $this->assertSame('submitted', app(UtilityBillFulfillmentService::class)->submitFromWorker($u->id, yieldToBacklog: true));
        $this->assertCount(1, $this->payCalls());

        // A slot further away is left to the sweeper (no long sleeps in a worker).
        $other = $this->makeOrder(['vendor' => null]);
        $this->prove($other->order);
        app(PaymentService::class)->completeOrder($other->order->fresh());
        $started = microtime(true);
        $this->assertSame('deferred', app(UtilityBillFulfillmentService::class)->submitFromWorker($other->id, yieldToBacklog: true));
        $this->assertLessThan(1, microtime(true) - $started);
    }

    public function test_admin_sees_a_capacity_backlog_derived_from_the_configured_budget(): void
    {
        Queue::fake();
        config(['utility_bills.rate.pay_per_minute' => 5]);
        foreach (range(1, 60) as $i) {
            $this->makeOrder(['paid' => true, 'vendor' => null]);
        }

        $p = app(UtilityBillPipeline::class)->snapshot();
        $this->assertSame(60, $p['awaiting_submission']);
        $this->assertSame(300, $p['pay_per_hour']);
        $this->assertSame(12, $p['drain_minutes']);
        $this->assertSame('capacity_backlog', $p['state']);
        $this->assertSame(60, $p['paid_last_hour']);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.utility-bill-sales.index'))->assertOk()
            ->assertSee('Provider capacity backlog')->assertSee('Pay capacity 5/min')->assertSee('300/hour')
            ->assertSee('provider documents 6/min per key');

        // A higher limit, once KiNG FLEXY confirms it, is configuration only.
        config(['utility_bills.rate.pay_per_minute' => 10]);
        $p = app(UtilityBillPipeline::class)->snapshot();
        $this->assertSame(600, $p['pay_per_hour']);
        $this->assertSame(6, $p['drain_minutes']);
        $this->assertSame('flowing', $p['state']);
    }
}
