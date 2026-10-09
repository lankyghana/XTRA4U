<?php

namespace Tests\Feature\UtilityBills;

use App\Jobs\SubmitUtilityBillPayment;
use App\Jobs\SyncUtilityBillStatus;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\User;
use App\Models\UtilityBillConfigAudit;
use App\Models\UtilityBillOrder;
use App\Services\UtilityBills\Exceptions\ProviderRateLimited;
use App\Services\UtilityBills\Exceptions\SaleNotAllowed;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\ProviderRateBudget;
use App\Services\UtilityBills\UtilityBillAvailability;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSettings;
use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Behaviour under load and provider trouble: storefront latency, shared budgets, wallet incidents, scheduler. */
class UtilityBillScalabilityTest extends UtilityBillTestCase
{
    private function walletAlerts(): int
    {
        return AdminNotification::query()->where('title', 'like', 'KiNG FLEXY wallet too low%')->count();
    }

    // ---- 1. a provider outage must not slow every storefront ------------------

    public function test_storefront_check_remembers_a_provider_failure_instead_of_waiting_again(): void
    {
        $this->openService(['ecg']);
        $calls = 0;
        $this->fake([self::BASE.'/utilities/billers' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('timeout');
        }]);
        $availability = app(UtilityBillAvailability::class);

        $this->assertCount(0, $availability->sellable());
        $this->assertCount(0, $availability->sellable());
        $this->assertCount(0, $availability->sellable());

        // Only the first storefront render tried the provider; the rest used the remembered failure.
        $this->assertSame(1, $calls);
    }

    public function test_remembered_failure_never_blocks_a_sale_check_and_clears_on_success(): void
    {
        $this->openService(['ecg']);
        $this->fake([self::BASE.'/utilities/billers' => fn () => throw new ConnectionException('timeout')]);
        app(UtilityBillAvailability::class)->sellable();

        // The provider recovers; the authoritative sale check still asks it.
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        [$biller] = app(UtilityBillAvailability::class)->assertSellable('ecg');
        $this->assertSame('ecg', $biller->key);

        // And the storefront sees the service again straight away.
        $this->assertCount(1, app(UtilityBillAvailability::class)->sellable());
    }

    public function test_admin_key_change_forgets_a_remembered_failure(): void
    {
        $this->openService(['ecg']);
        $this->fake([self::BASE.'/utilities/billers' => Http::response([], 401)]);
        app(KingFlexyUtilityProvider::class)->catalogForDisplay();

        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('admin.utility-bills.settings.credentials'), ['api_key' => 'kf_cs_live_newkey55555'])->assertRedirect();

        [$catalog, $stale] = app(KingFlexyUtilityProvider::class)->catalogForDisplay();
        $this->assertNotNull($catalog);
        $this->assertFalse($stale);
    }

    // ---- 2. no single visitor may use up the shared lookup budget --------------

    public function test_lookups_are_limited_per_session(): void
    {
        config(['utility_bills.lookup_limit.per_session_per_minute' => 3, 'utility_bills.lookup_limit.per_ip_per_minute' => 100]);
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['account_name' => 'KWAME MENSAH', 'account_number' => '7041234567', 'meters' => []]]),
        ]);

        // One browser: the same session cookie on every request.
        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));

        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '704123456'.$i])->assertOk();
        }

        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234569'])
            ->assertStatus(429)->assertJson(['success' => false, 'message' => 'Too many verification attempts. Please wait a minute and try again.']);
    }

    public function test_lookups_are_limited_per_ip_across_fresh_sessions(): void
    {
        config(['utility_bills.lookup_limit.per_session_per_minute' => 100, 'utility_bills.lookup_limit.per_ip_per_minute' => 6]);
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['account_name' => 'KWAME MENSAH', 'account_number' => '7041234567', 'meters' => []]]),
        ]);

        for ($i = 0; $i < 7; $i++) {
            // A bot that drops cookies gets a new session every time; the IP limit still applies.
            $r = $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '70412345'.$i.'0']);
            $i < 6 ? $r->assertOk() : $r->assertStatus(429);
        }
    }

    // ---- 3. our own pay budget must not use up an order's attempts -------------

    public function test_held_back_by_our_pay_budget_sends_nothing_counts_nothing_and_never_needs_an_admin(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        config(['utility_bills.rate.pay_per_minute' => 1]);
        $this->useUpBudget('pay');   // budget already used by other orders

        $u = $this->makeOrder(['paid' => true]);
        $svc = app(UtilityBillFulfillmentService::class);

        for ($i = 0; $i < 12; $i++) {
            UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subSecond()]);
            $this->assertSame('deferred', $svc->submit($u->id));
        }

        $u->refresh();
        $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
        $this->assertSame(0, (int) $u->submit_attempts);
        $this->assertFalse($u->next_submit_at->isFuture(), 'stays due so the sweeper submits it in turn');
        $this->assertCount(0, Http::recorded());

        // Budget frees up: the order goes through on its first counted attempt.
        app(ProviderRateBudget::class)->clear('pay');
        UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subSecond()]);
        $this->assertSame('submitted', $svc->submit($u->id));
        $this->assertSame(1, (int) $u->fresh()->submit_attempts);
    }

    public function test_storefronts_do_not_stampede_the_provider_while_another_request_refreshes_the_catalog(): void
    {
        config(['utility_bills.display_timeout' => 1]);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $provider = app(KingFlexyUtilityProvider::class);
        $provider->catalog();                                   // warm, then expire the fresh copy
        $provider->forgetCatalog();
        Http::swap(new Factory);
        Http::fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        // Another request is mid-refresh.
        $lock = Cache::lock('utility_bills.catalog.refresh', 30);
        $this->assertTrue($lock->get());

        $started = microtime(true);
        [$catalog, $stale] = $provider->catalogForDisplay();

        $this->assertCount(0, Http::recorded(), 'a waiting page called the provider');
        $this->assertLessThan(4, microtime(true) - $started, 'a page waited longer than the display budget');
        $this->assertNotNull($catalog);
        $this->assertTrue($stale, 'falls back to the last good copy');

        // Once the refresh is done, the next page refreshes normally (one call).
        $lock->release();
        $provider->forgetCatalog();
        [$catalog, $stale] = $provider->catalogForDisplay();
        $this->assertFalse($stale);
        $this->assertCount(1, Http::recorded());
    }

    public function test_deferred_and_retried_submissions_never_dispatch_their_own_jobs(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);
        $u = $this->makeOrder(['paid' => true]);                     // provider 429 -> requeued
        $this->assertSame(FulfillmentStatus::QUEUED, $u->fresh()->fulfillment_status);

        Queue::fake();
        config(['utility_bills.rate.pay_per_minute' => 1]);
        $this->useUpBudget('pay');
        UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subSecond()]);

        $this->assertSame('deferred', app(UtilityBillFulfillmentService::class)->submit($u->id));
        Queue::assertNothingPushed();
    }

    public function test_the_sweeper_submits_oldest_first_up_to_the_pay_budget(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);
        $orders = collect(range(1, 4))->map(fn () => $this->makeOrder(['paid' => true]));
        UtilityBillOrder::query()->update(['next_submit_at' => now()->subMinute()]);

        Queue::fake();
        config(['utility_bills.rate.pay_per_minute' => 2]);
        $result = app(UtilityBillSweeper::class)->run();

        $this->assertSame(2, $result['dispatched']);
        $pushed = Queue::pushed(SubmitUtilityBillPayment::class)->map(fn ($job) => $job->utilityBillOrderId)->sort()->values()->all();
        $this->assertSame([$orders[0]->id, $orders[1]->id], $pushed);
        Queue::assertPushed(SubmitUtilityBillPayment::class, fn ($job) => $job->fromSweeper);
    }

    public function test_a_sweeper_job_releases_its_slot_when_it_finishes(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);
        $u = $this->makeOrder(['paid' => true]);
        UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subMinute()]);

        Queue::fake();
        app(UtilityBillSweeper::class)->run();
        app(UtilityBillSweeper::class)->run();
        Queue::assertPushed(SubmitUtilityBillPayment::class, 1);   // still pending: no duplicate

        (new SubmitUtilityBillPayment($u->id, fromSweeper: true))->handle(app(UtilityBillFulfillmentService::class));
        UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subMinute()]);

        app(UtilityBillSweeper::class)->run();
        Queue::assertPushed(SubmitUtilityBillPayment::class, 2);
    }

    public function test_a_newly_paid_order_does_not_overtake_an_older_waiting_one(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);
        $older = $this->makeOrder(['paid' => true]);
        UtilityBillOrder::whereKey($older->id)->update(['next_submit_at' => now()->subMinute()]);

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-new1'))]);
        app(ProviderRateBudget::class)->clear('pay');
        $newer = $this->makeOrder(['paid' => true]);                  // immediate dispatch yields

        $this->assertSame(FulfillmentStatus::QUEUED, $newer->fresh()->fulfillment_status);
        $this->assertCount(0, Http::recorded());

        // The sweeper submits both, oldest first.
        app(UtilityBillSweeper::class)->run();
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]['reference'])->all();
        $this->assertSame(['XU-'.$older->public_ref.'-A1', 'XU-'.$newer->public_ref.'-A1'], $sent);
    }

    public function test_concurrent_callers_cannot_overshoot_the_local_budget(): void
    {
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('pending'))]);
        config(['utility_bills.rate.status_per_minute' => 2]);
        $p = app(KingFlexyUtilityProvider::class);

        $refused = 0;
        for ($i = 0; $i < 4; $i++) {
            try {
                $p->status('UTIL-DSTV-aaa111');
            } catch (ProviderRateLimited $e) {
                $this->assertTrue($e->local);
                $refused++;
            }
        }

        $this->assertSame(2, $refused);
        $this->assertCount(2, Http::recorded());
    }

    public function test_provider_429_and_409_do_not_use_up_attempts(): void
    {
        foreach ([429, 409] as $status) {
            $this->fake([self::BASE.'/utilities/pay' => Http::response([], $status)]);
            $u = $this->makeOrder(['paid' => true]);

            for ($i = 0; $i < 10; $i++) {
                app(ProviderRateBudget::class)->clear('pay');
                UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subSecond()]);
                $this->assertSame('requeued', app(UtilityBillFulfillmentService::class)->submit($u->id));
            }

            $u->refresh();
            $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status, "HTTP {$status}");
            $this->assertSame(0, (int) $u->submit_attempts, "HTTP {$status}");
            UtilityBillOrder::whereKey($u->id)->update(['fulfillment_status' => FulfillmentStatus::ATTENTION]);
        }
    }

    public function test_a_queued_order_whose_payment_is_no_longer_confirmed_is_parked_for_an_admin(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);
        $u = $this->makeOrder(['paid' => true]);
        Order::whereKey($u->order_id)->update(['payment_integrity_status' => 'mismatch']);
        UtilityBillOrder::whereKey($u->id)->update(['next_submit_at' => now()->subSecond()]);

        $this->assertSame('skipped:not_paid', app(UtilityBillFulfillmentService::class)->submit($u->id));

        $u->refresh();
        $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);
        $this->assertSame('payment_unconfirmed', $u->last_error_code);
        $this->assertSame(1, AdminNotification::query()->where('title', 'Utility Bill needs attention')->count());
        $this->assertSame(0, app(UtilityBillSweeper::class)->run()['dispatched']);
    }

    public function test_retry_delays_are_jittered(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);
        $delays = [];

        for ($i = 0; $i < 6; $i++) {
            app(ProviderRateBudget::class)->clear('pay');
            $u = $this->makeOrder(['paid' => true]);
            $delays[] = now()->diffInSeconds($u->fresh()->next_submit_at, true);
        }

        $this->assertGreaterThan(1, count(array_unique(array_map('intval', $delays))), 'all retries scheduled at the same moment');
        foreach ($delays as $d) {
            $this->assertGreaterThanOrEqual(29, $d);
            $this->assertLessThanOrEqual(61, $d);
        }
    }

    // ---- 4. an empty provider wallet: pause sales, one alert, no attempt burn --

    public function test_empty_provider_wallet_pauses_sales_alerts_once_and_does_not_burn_attempts(): void
    {
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400),
        ]);

        $a = $this->makeOrder(['paid' => true]);
        $b = $this->makeOrder(['paid' => true]);
        $svc = app(UtilityBillFulfillmentService::class);

        // Automatic retries keep failing while the wallet is empty.
        for ($i = 0; $i < 10; $i++) {
            app(ProviderRateBudget::class)->clear('pay');
            UtilityBillOrder::whereKey($a->id)->update(['fulfillment_status' => FulfillmentStatus::QUEUED, 'next_submit_at' => now()->subSecond()]);
            $svc->submit($a->id);
        }

        foreach ([$a, $b] as $u) {
            $u->refresh();
            $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);
            $this->assertSame('insufficient_balance', $u->last_error_code);
            $this->assertSame(0, (int) $u->submit_attempts, 'wallet failures must not count toward max attempts');
        }

        $this->assertSame(1, $this->walletAlerts(), 'one alert per incident, not per order or per retry');
        $this->assertSame(0, AdminNotification::query()->where('title', 'Utility Bill needs attention')->count());

        // New sales are paused everywhere.
        $this->assertNotNull(UtilityBillSettings::providerWalletPausedAt());
        $this->assertCount(0, app(UtilityBillAvailability::class)->sellable());
        $this->expectException(SaleNotAllowed::class);
        app(UtilityBillAvailability::class)->assertSellable('dstv');
    }

    public function test_admin_resume_reopens_sales_and_retries_waiting_orders_now(): void
    {
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400),
        ]);
        $u = $this->makeOrder(['paid' => true]);
        $this->assertTrue($u->fresh()->next_submit_at->isFuture());

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.utility-bills.settings'))->assertOk()->assertSee('New sales paused: KiNG FLEXY wallet too low');
        $this->post(route('admin.utility-bills.settings.resume-wallet'))->assertRedirect(route('admin.utility-bills.settings'));

        $this->assertNull(UtilityBillSettings::providerWalletPausedAt());
        $this->assertFalse($u->fresh()->next_submit_at->isFuture());
        $this->assertSame(1, UtilityBillConfigAudit::query()->where('scope', 'wallet')->count());
        $this->assertCount(1, app(UtilityBillAvailability::class)->sellable());

        // A later incident alerts again (one per incident).
        $before = $this->walletAlerts();
        app(ProviderRateBudget::class)->clear('pay');
        $this->makeOrder(['paid' => true]);
        $this->assertSame($before + 1, $this->walletAlerts());
    }

    public function test_a_successful_provider_payment_ends_the_pause_automatically(): void
    {
        $this->openService(['dstv']);
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400)]);
        $waiting = $this->makeOrder(['paid' => true]);
        $this->assertNotNull(UtilityBillSettings::providerWalletPausedAt());

        // Wallet topped up; the next submission succeeds.
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-ok1'))]);
        app(ProviderRateBudget::class)->clear('pay');
        UtilityBillOrder::whereKey($waiting->id)->update(['fulfillment_status' => FulfillmentStatus::QUEUED, 'next_submit_at' => now()->subSecond()]);
        $this->assertSame('submitted', app(UtilityBillFulfillmentService::class)->submit($waiting->id));

        $this->assertNull(UtilityBillSettings::providerWalletPausedAt());
    }

    public function test_other_attention_errors_alert_once_per_order_not_on_every_retry(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'disabled'], 503)]);
        $u = $this->makeOrder(['paid' => true]);
        $svc = app(UtilityBillFulfillmentService::class);

        for ($i = 0; $i < 4; $i++) {
            app(ProviderRateBudget::class)->clear('pay');
            UtilityBillOrder::whereKey($u->id)->update(['fulfillment_status' => FulfillmentStatus::QUEUED, 'next_submit_at' => now()->subSecond()]);
            $svc->submit($u->id);
        }

        // Service/biller disabled is provider-wide: one incident alert, not one per order or retry.
        $this->assertSame(1, AdminNotification::query()->where('type', 'utility_bill_incident')->count());
        $this->assertSame(0, AdminNotification::query()->where('title', 'Utility Bill needs attention')->count());

        // An order-specific rejection still alerts for that order, once.
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'invalid account'], 400)]);
        $other = $this->makeOrder(['paid' => true]);
        for ($i = 0; $i < 3; $i++) {
            app(ProviderRateBudget::class)->clear('pay');
            UtilityBillOrder::whereKey($other->id)->update(['fulfillment_status' => FulfillmentStatus::QUEUED, 'next_submit_at' => now()->subSecond()]);
            $svc->submit($other->id);
        }
        $this->assertSame(1, AdminNotification::query()->where('title', 'Utility Bill needs attention')->count());
    }

    // ---- 5. the scheduler never waits on the provider ---------------------------

    public function test_sweeper_queues_status_checks_instead_of_calling_the_provider(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-sw1'))]);
        $u = $this->makeOrder(['paid' => true]);
        UtilityBillOrder::whereKey($u->id)->update(['next_status_check_at' => now()->subMinute()]);

        Queue::fake();
        Http::swap(new Factory);
        Http::fake();

        $result = app(UtilityBillSweeper::class)->run();

        $this->assertSame(1, $result['polled']);
        Queue::assertPushed(SyncUtilityBillStatus::class, fn ($job) => $job->utilityBillOrderId === $u->id);
        $this->assertCount(0, Http::recorded(), 'the scheduler made a provider call');

        // A second run within the dedupe window does not queue a duplicate.
        app(UtilityBillSweeper::class)->run();
        Queue::assertPushed(SyncUtilityBillStatus::class, 1);
    }

    public function test_status_job_applies_the_provider_status(): void
    {
        $this->fake([
            self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-job1')),
            self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-DSTV-job1')),
        ]);
        $u = $this->makeOrder(['paid' => true]);

        (new SyncUtilityBillStatus($u->id))->handle(app(UtilityBillFulfillmentService::class));

        $this->assertSame(FulfillmentStatus::COMPLETED, $u->fresh()->fulfillment_status);
    }
}
