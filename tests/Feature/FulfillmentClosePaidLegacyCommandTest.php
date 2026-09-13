<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalFulfillment;
use App\Jobs\SyncExternalFulfillmentStatuses;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\VendorSetting;
use App\Services\Admin\PaymentHealthService;
use App\Services\ExternalFulfillment\ExternalFulfillmentStatusSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage for `fulfillment:close-paid-legacy` — administrative closure of
 * historical paid orders that PaymentHealthService::queueHealth() still
 * counts as "awaiting external fulfillment"
 * (external_fulfillment_status NULL/pending/queued/processing) but which
 * the administrator has confirmed were already delivered, regardless of
 * `status` (Processing/Completed/Cancelled/Pending all appear in the
 * confirmed population).
 *
 * The core guarantee under test: this command may only ever touch that
 * exact, narrow population, must never alter payment data or `status`,
 * must never contact a gateway/provider or dispatch a job, and must make
 * the closed records permanently immune to ProcessExternalFulfillment AND
 * to SyncExternalFulfillmentStatuses/ExternalFulfillmentStatusSynchronizer
 * (the polling/webhook path), which would otherwise be free to poll a
 * Processing order stamped 'succeeded' and auto-complete it.
 */
class FulfillmentClosePaidLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string,mixed>  $overrides
     */
    protected function order(array $overrides = []): Order
    {
        $vendor = $overrides['vendor'] ?? Vendor::factory()->create();
        unset($overrides['vendor']);

        // external_fulfillment_status/created_at are not mass-assignable on
        // Order — write those via a raw update after creation.
        $rawOnly = ['created_at', 'external_fulfillment_status', 'external_fulfillment_completed_at', 'external_fulfillment_delivered_at', 'external_fulfillment_provider_used', 'external_fulfillment_remote_reference', 'reconciliation_note'];
        $raw = array_intersect_key($overrides, array_flip($rawOnly));
        $fillableOverrides = array_diff_key($overrides, $raw);

        $order = Order::create(array_merge([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => 'MTN 1GB',
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'CLOSE-PAID-'.uniqid(),
            'payment_gateway' => 'paystack',
        ], $fillableOverrides));

        if ($raw) {
            DB::table('orders')->where('id', $order->id)->update($raw);
        }

        return $order->fresh();
    }

    protected function enableExternalFulfillmentForVendor(Vendor $vendor): void
    {
        foreach ([
            'external_fulfillment_enabled' => '1',
            'external_fulfillment_token' => 'test-token',
            'external_fulfillment_timeout_seconds' => '10',
        ] as $key => $value) {
            VendorSetting::setForVendor($vendor->id, $key, $value, 'external_fulfillment');
        }

        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);
    }

    // -----------------------------------------------------------------
    // Dry run / --execute / --before
    // -----------------------------------------------------------------

    public function test_dry_run_modifies_nothing(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => null,
        ]);

        $this->artisan('fulfillment:close-paid-legacy', ['--before' => now()->subMonths(1)->toDateString()])
            ->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertNull($fresh->external_fulfillment_status);
        $this->assertNull($fresh->reconciliation_note);
        $this->assertNull($fresh->external_fulfillment_completed_at);
        $this->assertSame('Processing', $fresh->status);
    }

    public function test_execute_flag_is_required_to_write_changes(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Pending',
            'external_fulfillment_status' => 'queued',
        ]);

        // No --execute supplied — must behave exactly like dry-run.
        $this->artisan('fulfillment:close-paid-legacy', ['--before' => now()->subMonths(1)->toDateString()])
            ->assertExitCode(0);

        $this->assertSame('queued', $order->fresh()->external_fulfillment_status);
    }

    public function test_before_option_is_mandatory(): void
    {
        $this->artisan('fulfillment:close-paid-legacy', ['--execute' => true])
            ->assertExitCode(1);

        $this->artisan('fulfillment:close-paid-legacy', ['--before' => 'not-a-date'])
            ->assertExitCode(1);
    }

    // -----------------------------------------------------------------
    // --execute changes only the eligible population, across every order
    // status the confirmed legacy population actually contains
    // -----------------------------------------------------------------

    public function test_execute_changes_only_eligible_orders_across_all_statuses(): void
    {
        $cutoff = now()->subMonths(1)->startOfDay();
        $old = fn () => (clone $cutoff)->subDay();

        // Eligible: paid, awaiting fulfillment (null/pending/queued/processing),
        // before cutoff — regardless of order status.
        $processingNull = $this->order(['created_at' => $old(), 'status' => 'Processing', 'external_fulfillment_status' => null]);
        $processingProcessing = $this->order(['created_at' => $old(), 'status' => 'Processing', 'external_fulfillment_status' => 'processing']);
        $completedNull = $this->order(['created_at' => $old(), 'status' => 'Completed', 'external_fulfillment_status' => null]);
        $cancelledNull = $this->order(['created_at' => $old(), 'status' => 'Cancelled', 'external_fulfillment_status' => null]);
        $pendingNull = $this->order(['created_at' => $old(), 'status' => 'Pending', 'external_fulfillment_status' => null]);
        $queued = $this->order(['created_at' => $old(), 'status' => 'Processing', 'external_fulfillment_status' => 'queued']);

        // Not eligible, for every distinct reason:
        $onCutoff = $this->order(['created_at' => clone $cutoff, 'status' => 'Processing', 'external_fulfillment_status' => null]);
        $current = $this->order(['created_at' => now(), 'status' => 'Processing', 'external_fulfillment_status' => null]);
        $unpaid = $this->order(['created_at' => $old(), 'payment_status' => 'unpaid', 'status' => 'Pending', 'external_fulfillment_status' => null]);
        $paymentFailed = $this->order(['created_at' => $old(), 'payment_status' => 'failed', 'status' => 'Failed', 'external_fulfillment_status' => null]);
        $alreadyFulfilled = $this->order(['created_at' => $old(), 'status' => 'Completed', 'external_fulfillment_status' => 'succeeded']);
        $fulfillmentFailed = $this->order(['created_at' => $old(), 'status' => 'Processing', 'external_fulfillment_status' => 'failed']);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => $cutoff->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertSame('succeeded', $processingNull->fresh()->external_fulfillment_status);
        $this->assertSame('succeeded', $processingProcessing->fresh()->external_fulfillment_status);
        $this->assertSame('succeeded', $completedNull->fresh()->external_fulfillment_status);
        $this->assertSame('succeeded', $cancelledNull->fresh()->external_fulfillment_status);
        $this->assertSame('succeeded', $pendingNull->fresh()->external_fulfillment_status);
        $this->assertSame('succeeded', $queued->fresh()->external_fulfillment_status);

        $this->assertNull($onCutoff->fresh()->external_fulfillment_status, 'On-cutoff record must never change.');
        $this->assertNull($current->fresh()->external_fulfillment_status, 'Current record must never change.');
        $this->assertNull($unpaid->fresh()->external_fulfillment_status, 'Unpaid record must never change.');
        $this->assertSame('failed', $paymentFailed->fresh()->payment_status, 'A failed payment must never be modified.');
        $this->assertSame('succeeded', $alreadyFulfilled->fresh()->external_fulfillment_status, 'Already-fulfilled order must remain exactly as-is.');
        $this->assertNull($alreadyFulfilled->fresh()->reconciliation_note, 'Already-fulfilled order must not be re-annotated.');
        $this->assertSame('failed', $fulfillmentFailed->fresh()->external_fulfillment_status, 'A recorded fulfillment failure must be left for a human, not auto-closed.');
    }

    // -----------------------------------------------------------------
    // `status` is preserved for every status the confirmed population
    // actually contains
    // -----------------------------------------------------------------

    public function test_order_status_is_preserved_for_every_confirmed_status(): void
    {
        $before = now()->subMonths(1)->toDateString();
        $created = now()->subMonths(6);

        $orders = [
            'Processing' => $this->order(['created_at' => $created, 'status' => 'Processing', 'external_fulfillment_status' => null]),
            'Completed' => $this->order(['created_at' => $created, 'status' => 'Completed', 'external_fulfillment_status' => null]),
            'Cancelled' => $this->order(['created_at' => $created, 'status' => 'Cancelled', 'external_fulfillment_status' => null]),
            'Pending' => $this->order(['created_at' => $created, 'status' => 'Pending', 'external_fulfillment_status' => 'pending']),
        ];

        $this->artisan('fulfillment:close-paid-legacy', ['--before' => $before, '--execute' => true])
            ->assertExitCode(0);

        foreach ($orders as $expectedStatus => $order) {
            $fresh = $order->fresh();
            $this->assertSame($expectedStatus, $fresh->status, "Status must be preserved for the {$expectedStatus} order.");
            $this->assertSame('succeeded', $fresh->external_fulfillment_status);
        }
    }

    // -----------------------------------------------------------------
    // Payment information (amount, reference, financial fields) unchanged
    // -----------------------------------------------------------------

    public function test_payment_information_is_unchanged(): void
    {
        $vendor = Vendor::factory()->create();
        $order = $this->order([
            'vendor' => $vendor,
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => 'processing',
            'amount_paid' => 37.50,
            'payment_reference' => 'CLOSE-PAID-PRESERVE',
        ]);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('succeeded', $fresh->external_fulfillment_status);
        $this->assertSame('paid', $fresh->payment_status, 'Payment state must be preserved exactly.');
        $this->assertEquals(37.50, (float) $fresh->amount_paid, 'Amount must never be altered.');
        $this->assertSame('CLOSE-PAID-PRESERVE', $fresh->payment_reference, 'Payment reference must be preserved.');
        $this->assertSame($vendor->id, $fresh->vendor_id, 'Vendor link must be preserved.');
        $this->assertSame('0244000000', $fresh->recipient_phone_number, 'Customer information must be preserved.');
        $this->assertSame('Processing', $fresh->status, 'Order status must be preserved exactly.');
    }

    // -----------------------------------------------------------------
    // No gateway/provider HTTP requests
    // -----------------------------------------------------------------

    public function test_no_external_http_requests_occur(): void
    {
        $vendor = Vendor::factory()->create();
        $this->enableExternalFulfillmentForVendor($vendor);

        $this->order([
            'vendor' => $vendor,
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => null,
        ]);

        Http::fake(); // Any HTTP call at all fails the assertion below.

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // No fulfillment jobs dispatched
    // -----------------------------------------------------------------

    public function test_no_fulfillment_jobs_are_dispatched(): void
    {
        Queue::fake();

        $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => 'processing',
        ]);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // Already-fulfilled orders remain untouched
    // -----------------------------------------------------------------

    public function test_already_fulfilled_orders_remain_untouched(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_completed_at' => now()->subMonths(5),
            'external_fulfillment_delivered_at' => now()->subMonths(5),
        ]);
        $originalCompletedAt = $order->external_fulfillment_completed_at;

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('succeeded', $fresh->external_fulfillment_status);
        $this->assertTrue($originalCompletedAt->equalTo($fresh->external_fulfillment_completed_at), 'An already-fulfilled order timestamp must not be rewritten.');
        $this->assertNull($fresh->reconciliation_note);
    }

    // -----------------------------------------------------------------
    // Administratively closed orders cannot subsequently be resubmitted
    // through ProcessExternalFulfillment
    // -----------------------------------------------------------------

    public function test_closed_order_cannot_subsequently_pass_through_external_fulfillment(): void
    {
        $vendor = Vendor::factory()->create();
        $this->enableExternalFulfillmentForVendor($vendor);

        $order = $this->order([
            'vendor' => $vendor,
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => null,
        ]);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Http::fake([
            'https://api.datafyhub.com/*' => Http::response(['status' => 'success'], 200),
        ]);

        // Simulate a stray re-dispatch of the fulfillment job for this order
        // id — ProcessExternalFulfillment does not check `status` at all, so
        // only external_fulfillment_status='succeeded' can stop it.
        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('succeeded', $order->fresh()->external_fulfillment_status);
        $this->assertSame('Processing', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Administratively closed Processing orders cannot subsequently be
    // resubmitted through the polling/webhook synchronizer either — the
    // exact "Processing + processing" shape (provider_used + remote
    // reference already on file) SyncExternalFulfillmentStatuses is
    // designed to actively re-poll and auto-complete.
    // -----------------------------------------------------------------

    public function test_closed_order_is_never_polled_by_the_external_fulfillment_synchronizer(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        VendorSetting::setForVendor($vendor->id, 'external_fulfillment_enabled', '1', 'external_fulfillment');
        VendorSetting::setForVendor($vendor->id, 'external_fulfillment_gigshub_enabled', '1', 'external_fulfillment');
        config()->set('services.gigshub.base_url', 'https://gigshub.test');
        config()->set('services.gigshub.api_key', 'test-key');

        $order = $this->order([
            'vendor' => $vendor,
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_provider_used' => 'gigshub',
            'external_fulfillment_remote_reference' => 'GH-LEGACY-1',
        ]);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response(['order' => ['status' => 'delivered']], 200),
        ]);

        SyncExternalFulfillmentStatuses::dispatchSync();

        Http::assertNothingSent();
        $fresh = $order->fresh();
        $this->assertSame('Processing', $fresh->status, 'A closed order must never be auto-completed by the polling job.');
        $this->assertSame('succeeded', $fresh->external_fulfillment_status);
    }

    public function test_closed_order_ignores_a_late_webhook_via_the_synchronizer(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_provider_used' => 'gigshub',
            'external_fulfillment_remote_reference' => 'GH-LEGACY-2',
        ]);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $outcome = app(ExternalFulfillmentStatusSynchronizer::class)->apply(
            $order->id,
            'gigshub',
            'delivered',
            ['source' => 'webhook-late']
        );

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_UNCHANGED, $outcome);
        $this->assertSame('Processing', $order->fresh()->status, 'A late webhook must never resurrect a closed order.');
    }

    // -----------------------------------------------------------------
    // Concurrency: a row that becomes ineligible between selection and the
    // row lock must be skipped, not clobbered.
    // -----------------------------------------------------------------

    public function test_concurrent_change_between_selection_and_lock_is_skipped(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => null,
        ]);

        // Simulate another process winning a real fulfillment attempt for
        // this exact order in the instant between this command's initial
        // `chunkById` id-only selection and its row-locked re-verification:
        // hook the first (id-only) hydration and mutate the row before the
        // command ever issues its `lockForUpdate()` re-check SELECT, so
        // that SELECT reads the already-changed row exactly as a genuine
        // concurrent writer's committed change would be read.
        $raced = false;
        Order::retrieved(function (Order $retrieved) use ($order, &$raced) {
            if (! $raced
                && $retrieved->getKey() === $order->id
                && ! array_key_exists('payment_status', $retrieved->getAttributes())
            ) {
                $raced = true;
                DB::table('orders')->where('id', $order->id)->update([
                    'external_fulfillment_status' => 'failed',
                    'external_fulfillment_last_error' => 'Concurrent real attempt failed.',
                ]);
            }
        });

        try {
            $this->artisan('fulfillment:close-paid-legacy', [
                '--before' => now()->subMonths(1)->toDateString(),
                '--execute' => true,
            ])->assertExitCode(0);
        } finally {
            Order::flushEventListeners();
        }

        $this->assertTrue($raced, 'The race hook must have fired for this test to prove anything.');
        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->external_fulfillment_status, 'The concurrently-written real outcome must win, not be overwritten.');
        $this->assertNull($fresh->reconciliation_note, 'A record that became ineligible under the lock must never be annotated.');
    }

    // -----------------------------------------------------------------
    // Disappears from Payment Health's "awaiting fulfillment" count,
    // including for non-Completed order statuses
    // -----------------------------------------------------------------

    public function test_closed_records_disappear_from_payment_health_awaiting_fulfillment_count(): void
    {
        $this->order(['created_at' => now()->subMonths(6), 'status' => 'Processing', 'external_fulfillment_status' => null]);
        $this->order(['created_at' => now()->subMonths(6), 'status' => 'Cancelled', 'external_fulfillment_status' => null]);
        $this->order(['created_at' => now()->subMonths(6), 'status' => 'Pending', 'external_fulfillment_status' => 'pending']);

        $before = app(PaymentHealthService::class)->queueHealth();
        $this->assertSame(3, $before['orders_awaiting_fulfillment']);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $after = app(PaymentHealthService::class)->queueHealth();
        $this->assertSame(0, $after['orders_awaiting_fulfillment']);
    }

    // -----------------------------------------------------------------
    // A second run finds and changes zero already-closed records
    // -----------------------------------------------------------------

    public function test_repeated_execution_finds_zero_eligible_records(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => null,
        ]);

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $updatedAtAfterFirstRun = $order->fresh()->updated_at;

        $this->artisan('fulfillment:close-paid-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])
            ->expectsOutputToContain('Orders: closed 0 record(s).')
            ->assertExitCode(0);

        $this->assertTrue(
            $updatedAtAfterFirstRun->equalTo($order->fresh()->updated_at),
            'A second run must write zero already-closed records.'
        );
    }
}
