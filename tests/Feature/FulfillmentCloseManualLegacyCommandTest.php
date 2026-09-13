<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalFulfillment;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\VendorSetting;
use App\Services\Admin\PaymentHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage for `fulfillment:close-manual-legacy` — administrative closure
 * of historical paid+Completed orders that a vendor delivered manually
 * while the external fulfillment provider was offline.
 *
 * The core guarantee under test throughout: this command may only ever
 * touch a narrow, strongly-evidenced population (paid, vendor/admin marked
 * Completed, never recorded as externally fulfilled) and must make that
 * population permanently invisible to Payment Health's
 * "awaiting fulfillment" count and permanently immune to
 * ProcessExternalFulfillment — without ever making a gateway/provider
 * call, dispatching a job, or altering financial data.
 */
class FulfillmentCloseManualLegacyCommandTest extends TestCase
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
        $rawOnly = ['created_at', 'external_fulfillment_status', 'external_fulfillment_completed_at', 'external_fulfillment_delivered_at', 'reconciliation_note'];
        $raw = array_intersect_key($overrides, array_flip($rawOnly));
        $fillableOverrides = array_diff_key($overrides, $raw);

        $order = Order::create(array_merge([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => 'MTN 1GB',
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'CLOSE-MANUAL-'.uniqid(),
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
            'external_fulfillment_status' => null,
        ]);

        $this->artisan('fulfillment:close-manual-legacy', ['--before' => now()->subMonths(1)->toDateString()])
            ->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertNull($fresh->external_fulfillment_status);
        $this->assertNull($fresh->reconciliation_note);
        $this->assertNull($fresh->external_fulfillment_completed_at);
    }

    public function test_execute_flag_is_required_to_write_changes(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'external_fulfillment_status' => 'failed',
        ]);

        // No --execute supplied — must behave exactly like dry-run.
        $this->artisan('fulfillment:close-manual-legacy', ['--before' => now()->subMonths(1)->toDateString()])
            ->assertExitCode(0);

        $this->assertSame('failed', $order->fresh()->external_fulfillment_status);
    }

    public function test_before_option_is_mandatory(): void
    {
        $this->artisan('fulfillment:close-manual-legacy', ['--execute' => true])
            ->assertExitCode(1);

        $this->artisan('fulfillment:close-manual-legacy', ['--before' => 'not-a-date'])
            ->assertExitCode(1);
    }

    // -----------------------------------------------------------------
    // --execute changes only the eligible population
    // -----------------------------------------------------------------

    public function test_execute_changes_only_eligible_orders(): void
    {
        $cutoff = now()->subMonths(1)->startOfDay();

        // Eligible: paid, Completed, never recorded as fulfilled, before cutoff.
        $eligibleNull = $this->order(['created_at' => (clone $cutoff)->subDay(), 'external_fulfillment_status' => null]);
        $eligibleFailed = $this->order(['created_at' => (clone $cutoff)->subDay(), 'external_fulfillment_status' => 'failed']);

        // Not eligible, for every distinct reason:
        $onCutoff = $this->order(['created_at' => clone $cutoff, 'external_fulfillment_status' => null]);
        $current = $this->order(['created_at' => now(), 'external_fulfillment_status' => null]);
        $unpaid = $this->order(['created_at' => (clone $cutoff)->subDay(), 'payment_status' => 'unpaid', 'status' => 'Pending', 'external_fulfillment_status' => null]);
        $paymentFailed = $this->order(['created_at' => (clone $cutoff)->subDay(), 'payment_status' => 'failed', 'status' => 'Failed', 'external_fulfillment_status' => null]);
        $notCompleted = $this->order(['created_at' => (clone $cutoff)->subDay(), 'status' => 'Processing', 'external_fulfillment_status' => null]);
        $alreadyFulfilled = $this->order(['created_at' => (clone $cutoff)->subDay(), 'external_fulfillment_status' => 'succeeded']);
        $ambiguousProcessing = $this->order(['created_at' => (clone $cutoff)->subDay(), 'external_fulfillment_status' => 'processing']);

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => $cutoff->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertSame('succeeded', $eligibleNull->fresh()->external_fulfillment_status);
        $this->assertSame('succeeded', $eligibleFailed->fresh()->external_fulfillment_status);

        $this->assertNull($onCutoff->fresh()->external_fulfillment_status, 'On-cutoff record must never change.');
        $this->assertNull($current->fresh()->external_fulfillment_status, 'Current record must never change.');
        $this->assertNull($unpaid->fresh()->external_fulfillment_status, 'Unpaid record must never change.');
        $this->assertSame('failed', $paymentFailed->fresh()->payment_status, 'A failed payment must never be modified.');
        $this->assertNull($notCompleted->fresh()->external_fulfillment_status, 'Paid-but-not-completed order must never change.');
        $this->assertSame('succeeded', $alreadyFulfilled->fresh()->external_fulfillment_status, 'Already-fulfilled order must remain exactly as-is.');
        $this->assertSame('processing', $ambiguousProcessing->fresh()->external_fulfillment_status, 'Ambiguous in-flight order must never be closed.');
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
            'external_fulfillment_status' => 'failed',
            'amount_paid' => 37.50,
            'payment_reference' => 'CLOSE-MANUAL-PRESERVE',
        ]);

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('succeeded', $fresh->external_fulfillment_status);
        $this->assertSame('paid', $fresh->payment_status, 'Payment state must be preserved exactly.');
        $this->assertEquals(37.50, (float) $fresh->amount_paid, 'Amount must never be altered.');
        $this->assertSame('CLOSE-MANUAL-PRESERVE', $fresh->payment_reference, 'Payment reference must be preserved.');
        $this->assertSame($vendor->id, $fresh->vendor_id, 'Vendor link must be preserved.');
        $this->assertSame('0244000000', $fresh->recipient_phone_number, 'Customer information must be preserved.');
        $this->assertSame('Completed', $fresh->status, 'Vendor completion status must be preserved.');
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
            'external_fulfillment_status' => null,
        ]);

        Http::fake(); // Any HTTP call at all fails the assertion below.

        $this->artisan('fulfillment:close-manual-legacy', [
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
            'external_fulfillment_status' => 'failed',
        ]);

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // Paid-but-not-completed orders remain untouched
    // -----------------------------------------------------------------

    public function test_paid_but_not_completed_orders_remain_untouched(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'status' => 'Processing',
            'external_fulfillment_status' => null,
        ]);

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('Processing', $fresh->status);
        $this->assertNull($fresh->external_fulfillment_status);
        $this->assertNull($fresh->reconciliation_note);
    }

    // -----------------------------------------------------------------
    // Already-fulfilled orders remain untouched
    // -----------------------------------------------------------------

    public function test_already_fulfilled_orders_remain_untouched(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_completed_at' => now()->subMonths(5),
            'external_fulfillment_delivered_at' => now()->subMonths(5),
        ]);
        $originalCompletedAt = $order->external_fulfillment_completed_at;

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('succeeded', $fresh->external_fulfillment_status);
        $this->assertTrue($originalCompletedAt->equalTo($fresh->external_fulfillment_completed_at), 'An already-fulfilled order timestamp must not be rewritten.');
        $this->assertNull($fresh->reconciliation_note);
    }

    // -----------------------------------------------------------------
    // Administratively closed orders cannot subsequently pass through
    // external fulfillment
    // -----------------------------------------------------------------

    public function test_closed_order_cannot_subsequently_pass_through_external_fulfillment(): void
    {
        $vendor = Vendor::factory()->create();
        $this->enableExternalFulfillmentForVendor($vendor);

        $order = $this->order([
            'vendor' => $vendor,
            'created_at' => now()->subMonths(6),
            'external_fulfillment_status' => 'failed',
        ]);

        $this->artisan('fulfillment:close-manual-legacy', [
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
    }

    // -----------------------------------------------------------------
    // Disappears from Payment Health's "awaiting fulfillment" count
    // -----------------------------------------------------------------

    public function test_closed_records_disappear_from_payment_health_awaiting_fulfillment_count(): void
    {
        $this->order([
            'created_at' => now()->subMonths(6),
            'external_fulfillment_status' => null,
        ]);

        $before = app(PaymentHealthService::class)->queueHealth();
        $this->assertSame(1, $before['orders_awaiting_fulfillment']);

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $after = app(PaymentHealthService::class)->queueHealth();
        $this->assertSame(0, $after['orders_awaiting_fulfillment']);
    }

    // -----------------------------------------------------------------
    // Idempotency (bonus): a second run changes zero already-closed records
    // -----------------------------------------------------------------

    public function test_repeated_execution_is_idempotent(): void
    {
        $order = $this->order([
            'created_at' => now()->subMonths(6),
            'external_fulfillment_status' => null,
        ]);

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $updatedAtAfterFirstRun = $order->fresh()->updated_at;

        $this->artisan('fulfillment:close-manual-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertTrue(
            $updatedAtAfterFirstRun->equalTo($order->fresh()->updated_at),
            'A second run must write zero already-closed records.'
        );
    }
}
