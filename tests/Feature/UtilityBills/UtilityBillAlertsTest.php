<?php

namespace Tests\Feature\UtilityBills;

use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\UtilityBillOrder;
use App\Services\SmsService;
use App\Services\UtilityBills\Data\StatusResult;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\ProviderRateBudget;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;

/** Stuck-order alerts, atomic "send once" side effects, and database cache pruning. */
class UtilityBillAlertsTest extends UtilityBillTestCase
{
    private function stuckAlerts(): int
    {
        return AdminNotification::query()->where('title', 'Utility Bill still processing')->count();
    }

    private function paidMinutesAgo(UtilityBillOrder $u, int $minutes): void
    {
        $at = now()->subMinutes($minutes);
        UtilityBillOrder::whereKey($u->id)->update(['created_at' => $at]);
        Order::whereKey($u->order_id)->update(['payment_completed_at' => $at]);
    }

    // ---- stuck alerts -------------------------------------------------------------

    public function test_an_order_never_accepted_by_the_provider_alerts_once_after_the_threshold(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response([], 429)]);   // keeps it queued
        $u = $this->makeOrder(['paid' => true]);
        $this->assertSame(FulfillmentStatus::QUEUED, $u->fresh()->fulfillment_status);
        Queue::fake();

        $this->paidMinutesAgo($u, 30);
        $this->assertSame(0, app(UtilityBillSweeper::class)->run()['alerted'], 'not before the threshold');

        $this->paidMinutesAgo($u, 121);
        $this->assertSame(1, app(UtilityBillSweeper::class)->run()['alerted']);
        $this->assertSame(0, app(UtilityBillSweeper::class)->run()['alerted'], 'only once');

        $this->assertSame(1, $this->stuckAlerts());
        $this->assertStringContainsString('has not been accepted by KiNG FLEXY yet', AdminNotification::query()->latest('id')->value('message'));
        $this->assertNotNull($u->fresh()->stuck_alerted_at);
        $this->assertSame(FulfillmentStatus::QUEUED, $u->fresh()->fulfillment_status, 'alerting never changes the order');
    }

    public function test_a_provider_order_whose_status_checks_keep_failing_still_alerts(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-st1'))]);
        $u = $this->makeOrder(['paid' => true]);

        $this->fake([self::BASE.'/utilities/orders/*' => Http::response([], 500)]);
        $this->assertSame('error', app(UtilityBillFulfillmentService::class)->syncStatus($u->id));
        $this->assertSame(0, $this->stuckAlerts());

        $this->paidMinutesAgo($u, 180);
        Queue::fake();
        app(UtilityBillSweeper::class)->run();

        $this->assertSame(1, $this->stuckAlerts());
        $this->assertStringContainsString('has not reached a final provider status', AdminNotification::query()->latest('id')->value('message'));
    }

    public function test_finished_unpaid_and_attention_orders_never_raise_a_stuck_alert(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-done'))]);
        $completed = $this->makeOrder(['paid' => true]);
        $completed->forceFill(['fulfillment_status' => FulfillmentStatus::COMPLETED])->save();

        $unpaid = $this->makeOrder();                                        // awaiting payment
        $attention = $this->makeOrder(['paid' => true]);
        $attention->forceFill(['fulfillment_status' => FulfillmentStatus::ATTENTION, 'provider_order_reference' => null])->save();

        foreach ([$completed, $unpaid, $attention] as $u) {
            $this->paidMinutesAgo($u, 500);
        }
        Queue::fake();

        $this->assertSame(0, app(UtilityBillSweeper::class)->run()['alerted']);
        $this->assertSame(0, $this->stuckAlerts());
    }

    // ---- send once ----------------------------------------------------------------

    public function test_the_customer_sms_is_sent_once_however_many_callers_see_completion(): void
    {
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')->once()->andReturn(true);
        $this->app->instance(SmsService::class, $sms);

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-sms1'))]);
        $u = $this->makeOrder(['paid' => true, 'phone' => '0551617309']);
        $svc = app(UtilityBillFulfillmentService::class);

        // Sync job, admin Refresh and a replayed status all observe "completed".
        $completed = new StatusResult('UTIL-DSTV-sms1', 'completed', 'paid', null, null, null, null);
        $svc->applyProviderStatus($u->id, $completed);
        $svc->applyProviderStatus($u->id, $completed);
        $svc->afterStatusChange($u->id);

        $this->assertNotNull($u->fresh()->customer_notified_at);
        $this->assertSame(1, $u->events()->where('kind', 'customer_notified')->count());
    }

    public function test_the_marker_not_the_event_log_decides_whether_to_send(): void
    {
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsService::class, $sms);

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-sms2'))]);
        $u = $this->makeOrder(['paid' => true, 'phone' => '0551617309']);

        // Another caller already claimed the send (its event row not yet written).
        UtilityBillOrder::whereKey($u->id)->update(['customer_notified_at' => now(), 'fulfillment_status' => FulfillmentStatus::COMPLETED]);
        app(UtilityBillFulfillmentService::class)->afterStatusChange($u->id);

        $this->assertSame(0, $u->events()->where('kind', 'customer_notified')->count());
    }

    public function test_the_terminal_alert_is_raised_once(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-rf1'))]);
        $u = $this->makeOrder(['paid' => true]);
        $svc = app(UtilityBillFulfillmentService::class);

        $refunded = new StatusResult('UTIL-DSTV-rf1', 'refunded', null, 'biller rejected', null, null, null);
        $svc->applyProviderStatus($u->id, $refunded);
        $svc->afterStatusChange($u->id);
        $svc->afterStatusChange($u->id);

        $this->assertSame(1, AdminNotification::query()->where('title', 'Utility Bill not completed')->count());
        $this->assertNotNull($u->fresh()->terminal_alerted_at);
    }

    public function test_a_new_provider_attempt_can_alert_again(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-na1'))]);
        $u = $this->makeOrder(['paid' => true]);
        $svc = app(UtilityBillFulfillmentService::class);
        $svc->applyProviderStatus($u->id, new StatusResult('UTIL-DSTV-na1', 'refunded', null, null, null, null, null));
        UtilityBillOrder::whereKey($u->id)->update(['stuck_alerted_at' => now()]);

        Queue::fake();
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded', 'UTIL-DSTV-na1'))]);
        app(ProviderRateBudget::class)->clear('status');
        $result = $svc->adminRetry($u->id, ['id' => 1, 'email' => 'a@x.test'], newAttempt: true, reason: 'customer still needs the bill');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertNull($u->fresh()->terminal_alerted_at);
        $this->assertNull($u->fresh()->stuck_alerted_at);
    }

    // ---- database cache pruning ---------------------------------------------------

    public function test_expired_cache_rows_and_locks_are_pruned_and_live_ones_kept(): void
    {
        config(['cache.default' => 'database']);
        DB::table('cache')->insert([
            ['key' => 'utility_bills.lookup_token.expired', 'value' => 's:4:"KWAM";', 'expiration' => time() - 10],
            ['key' => 'utility_bills.lookup_token.live', 'value' => 's:4:"ABENA";', 'expiration' => time() + 600],
        ]);
        DB::table('cache_locks')->insert([
            ['key' => 'old-lock', 'owner' => 'a', 'expiration' => time() - 10],
            ['key' => 'live-lock', 'owner' => 'b', 'expiration' => time() + 600],
        ]);

        $this->artisan('xtra4u:prune-expired-cache', ['--chunk' => 1])->assertSuccessful();

        $this->assertSame(['utility_bills.lookup_token.live'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['live-lock'], DB::table('cache_locks')->pluck('key')->all());
    }

    public function test_pruning_is_a_no_op_for_a_non_database_cache_store(): void
    {
        config(['cache.default' => 'array']);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => time() - 10]);

        $this->artisan('xtra4u:prune-expired-cache')->assertSuccessful();

        $this->assertSame(1, DB::table('cache')->count());
    }
}
