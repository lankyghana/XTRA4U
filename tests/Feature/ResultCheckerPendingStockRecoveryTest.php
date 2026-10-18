<?php

namespace Tests\Feature;

use App\Jobs\FulfillPendingOrdersJob;
use App\Models\NetworkService;
use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\Vendor;
use App\Services\ResultCheckerService;
use App\Services\SmsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Paid Results Checker orders waiting for PIN stock: customer notice, scheduled
 * and event-driven recovery, idempotency, and never allocating before payment.
 */
class ResultCheckerPendingStockRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    private NetworkService $service;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->service = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20]);
        $this->smsAlwaysSucceeds();
    }

    private function smsAlwaysSucceeds(): void
    {
        $this->sent = [];
        $this->mock(SmsService::class, function (MockInterface $m) {
            $m->shouldReceive('sendResultCheckerPin')->andReturnUsing(function (string $to, string $message) {
                $this->sent[] = $message;

                return true;
            });
        });
    }

    private function order(array $attrs = []): ResultCheckerOrder
    {
        return ResultCheckerOrder::factory()->create(array_merge([
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'pending_payment',
            'total_price' => 25,
            'unit_price' => 25,
            'vendor_profit' => 5,
        ], $attrs));
    }

    private function pin(array $attrs = []): ResultCheckerPin
    {
        return ResultCheckerPin::factory()->create(array_merge(['service_id' => $this->service->id], $attrs));
    }

    private function pay(ResultCheckerOrder $order, ?string $ref = null): void
    {
        app(ResultCheckerService::class)->handlePaymentCallback($order, $ref ?? 'REF-'.$order->id, 'paystack');
    }

    // ------------------------------------------------------------- notification

    public function test_customer_is_told_once_when_a_verified_payment_is_waiting_for_stock(): void
    {
        $order = $this->order();

        $this->pay($order);
        $this->pay($order); // duplicate webhook delivery

        $this->assertSame('pending_stock', $order->fresh()->status);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Payment received', $this->sent[0]);
        $this->assertStringNotContainsString('PIN:', $this->sent[0]);
        $this->assertNull($order->fresh()->sms_sent_at, 'sms_sent_at still means "PIN delivered".');
    }

    public function test_no_pending_notice_when_stock_is_available_or_payment_is_unverified(): void
    {
        $this->pin();
        $paid = $this->order();
        $this->pay($paid);

        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('PIN:', $this->sent[0]);

        $unpaid = $this->order(['status' => 'pending_stock', 'paid_at' => null]);
        $this->assertFalse(app(ResultCheckerService::class)->sendPendingStockNotification($unpaid));
        $this->assertCount(1, $this->sent);
    }

    public function test_a_failed_notice_can_be_retried_but_a_sent_one_cannot(): void
    {
        $order = $this->order(['status' => 'pending_stock', 'paid_at' => now()]);

        $this->mock(SmsService::class, function (MockInterface $m) {
            $m->shouldReceive('sendResultCheckerPin')->twice()->andReturn(false, true);
        });
        $service = app(ResultCheckerService::class);

        $this->assertFalse($service->sendPendingStockNotification($order));
        $this->assertTrue($service->sendPendingStockNotification($order));
        $this->assertFalse($service->sendPendingStockNotification($order));
    }

    // ----------------------------------------------------------------- recovery

    public function test_job_fulfils_only_paid_orders_oldest_first_and_notifies_delivery_once(): void
    {
        $first = $this->order(['status' => 'pending_stock', 'paid_at' => now(), 'created_at' => now()->subHour()]);
        $second = $this->order(['status' => 'pending_stock', 'paid_at' => now(), 'created_at' => now()]);
        $unpaid = $this->order(['status' => 'pending_stock', 'paid_at' => null, 'created_at' => now()->subDay()]);
        $this->pin();

        (new FulfillPendingOrdersJob($this->service->id))->handle(app(ResultCheckerService::class));
        (new FulfillPendingOrdersJob($this->service->id))->handle(app(ResultCheckerService::class)); // re-run is a no-op

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('pending_stock', $second->fresh()->status, 'Only one PIN existed.');
        $this->assertSame('pending_stock', $unpaid->fresh()->status);
        $this->assertCount(0, $unpaid->fresh()->pins, 'No PIN before payment is verified.');
        $this->assertCount(1, $first->fresh()->pins);

        $deliveries = array_values(array_filter($this->sent, fn ($m) => str_contains($m, 'PIN:')));
        $this->assertCount(1, $deliveries);
        $this->assertNotNull($first->fresh()->sms_sent_at);
    }

    public function test_recovery_dispatches_nothing_without_stock_or_without_waiting_orders(): void
    {
        Queue::fake();
        $service = app(ResultCheckerService::class);

        $this->assertSame(0, $service->dispatchPendingStockRecovery(), 'Nobody waiting.');

        $this->order(['status' => 'pending_stock', 'paid_at' => now()]);
        $this->assertSame(0, $service->dispatchPendingStockRecovery(), 'Waiting but no stock.');

        $this->order(['status' => 'pending_stock', 'paid_at' => null]);
        $this->pin();
        $other = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20]);
        ResultCheckerPin::factory()->create(['service_id' => $other->id]);

        $this->assertSame(1, $service->dispatchPendingStockRecovery(), 'Only the service with a paid waiting order and stock.');
        Queue::assertPushed(FulfillPendingOrdersJob::class, 1);
        Queue::assertPushed(FulfillPendingOrdersJob::class, fn ($job) => $job->serviceId === $this->service->id);
    }

    public function test_unpaid_waiting_orders_never_trigger_recovery(): void
    {
        Queue::fake();
        $this->order(['status' => 'pending_stock', 'paid_at' => null]);
        $this->pin();

        $this->assertSame(0, app(ResultCheckerService::class)->dispatchPendingStockRecovery());
        Queue::assertNothingPushed();
    }

    public function test_returning_pins_to_inventory_triggers_recovery(): void
    {
        $holder = $this->order(['status' => 'processing', 'paid_at' => now()]);
        $this->pin(['status' => 'sold', 'result_checker_order_id' => $holder->id, 'sold_at' => now()]);
        $waiting = $this->order(['status' => 'pending_stock', 'paid_at' => now()]);

        Queue::fake();
        $released = app(ResultCheckerService::class)->releasePinsForOrder($holder);

        $this->assertSame(1, $released);
        Queue::assertPushed(FulfillPendingOrdersJob::class, fn ($job) => $job->serviceId === $this->service->id);

        // Run it for real: the waiting order gets the released PIN.
        (new FulfillPendingOrdersJob($this->service->id))->handle(app(ResultCheckerService::class));
        $this->assertSame('completed', $waiting->fresh()->status);
    }

    public function test_admin_mark_failed_returning_pins_triggers_recovery(): void
    {
        $holder = $this->order(['status' => 'processing', 'paid_at' => now()]);
        $this->pin(['status' => 'sold', 'result_checker_order_id' => $holder->id, 'sold_at' => now()]);
        $this->order(['status' => 'pending_stock', 'paid_at' => now()]);

        Queue::fake();
        $result = app(ResultCheckerService::class)->adminMarkFailed($holder);

        $this->assertTrue($result['ok']);
        $this->assertSame('failed', $holder->fresh()->status);
        Queue::assertPushed(FulfillPendingOrdersJob::class, 1);
    }

    public function test_releasing_nothing_dispatches_nothing(): void
    {
        $holder = $this->order(['status' => 'processing', 'paid_at' => now()]);
        $this->order(['status' => 'pending_stock', 'paid_at' => now()]);

        Queue::fake();
        $this->assertSame(0, app(ResultCheckerService::class)->releasePinsForOrder($holder));
        Queue::assertNothingPushed();
    }

    public function test_the_recovery_sweep_is_scheduled(): void
    {
        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();

        $this->assertContains('result-checkers:fulfil-pending-stock', $names);
    }

    public function test_pricing_and_vendor_profit_are_untouched_by_recovery(): void
    {
        $order = $this->order(['status' => 'pending_stock', 'paid_at' => now()]);
        $this->pin();

        (new FulfillPendingOrdersJob($this->service->id))->handle(app(ResultCheckerService::class));

        $fresh = $order->fresh();
        $this->assertSame('25.00', number_format((float) $fresh->total_price, 2, '.', ''));
        $this->assertSame('5.00', number_format((float) $fresh->vendor_profit, 2, '.', ''));
    }
}
