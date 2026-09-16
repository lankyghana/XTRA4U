<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalFulfillment;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hardens ProcessExternalFulfillment against duplicate provider submissions
 * and HTTP 409 failures (2026-09-13 production incident: two already-
 * submitted orders were resubmitted and landed in failed_jobs on a 409).
 *
 * @see \App\Jobs\ProcessExternalFulfillment
 * @see \App\Services\ExternalFulfillment\ExternalFulfillmentStatusSynchronizer
 */
class ExternalFulfillmentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function enableDatafyhub(Vendor $vendor): void
    {
        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        foreach ([
            'external_fulfillment_enabled' => '1',
            'external_fulfillment_token' => 'test-token',
            'external_fulfillment_timeout_seconds' => '10',
        ] as $key => $value) {
            VendorSetting::setForVendor($vendor->id, $key, $value, 'external_fulfillment');
        }
    }

    private function enableGigshub(Vendor $vendor): void
    {
        config([
            'services.gigshub.base_url' => 'https://gigshub.test',
            'services.gigshub.api_key' => 'test-key',
        ]);

        foreach ([
            'external_fulfillment_enabled' => '1',
            'external_fulfillment_gigshub_enabled' => '1',
        ] as $key => $value) {
            VendorSetting::setForVendor($vendor->id, $key, $value, 'external_fulfillment');
        }
    }

    private function makeOrder(Vendor $vendor, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'expected_amount' => 10.00,
            'currency' => 'GHS',
            'pricing_snapshot_at' => now(),
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-'.uniqid(),
            'payment_gateway' => 'test',
        ], $overrides));

        // Delivering real goods now requires proof that a trusted payment
        // source satisfied this order's terms, not merely payment_status=paid
        // (see PaymentIntegrity). These fixtures stand in for orders a gateway
        // already verified, so they carry that proof.
        if (! array_key_exists('payment_integrity_status', $overrides)) {
            $order->forceFill([
                'payment_integrity_status' => \App\Support\PaymentIntegrity::VERIFIED,
            ])->save();
        }

        return $order;
    }

    // 1. Normal first submission -------------------------------------------------

    public function test_normal_first_submission_succeeds_and_persists_state(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response(['reference' => 'DF-001'], 200),
        ]);

        $order = $this->makeOrder($vendor);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertSentCount(1);

        $order->refresh();
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertSame('DF-001', $order->external_fulfillment_remote_reference);
        $this->assertNotNull($order->external_fulfillment_idempotency_key);
        $this->assertNotNull($order->external_fulfillment_completed_at);
    }

    // 2. Queue retry after provider already accepted the first request ----------

    public function test_retry_after_provider_already_accepted_polls_instead_of_resubmitting(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableGigshub($vendor);

        $order = $this->makeOrder($vendor);
        Order::whereKey($order->id)->update([
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_remote_reference' => 'GH-ORDER-1',
            'external_fulfillment_provider_used' => 'gigshub',
            'external_fulfillment_idempotency_key' => 'order-'.$order->id,
        ]);

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response(['order' => ['status' => 'processing']], 200),
        ]);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST';
        });
        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/api/v1/order/status/');
        });

        $order->refresh();
        $this->assertSame('processing', $order->external_fulfillment_status);
    }

    // 3. Provider duplicate 409 (confirmed) --------------------------------------

    public function test_confirmed_duplicate_409_recovers_without_throwing_or_retrying(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response([
                'error' => 'Order already exists for this reference.',
            ], 409),
        ]);

        $order = $this->makeOrder($vendor);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertSentCount(1);

        $order->refresh();
        $this->assertSame('processing', $order->external_fulfillment_status);
        $this->assertSame('duplicate-order-detected', $order->external_fulfillment_remote_reference);
        $this->assertNull($order->external_fulfillment_last_error);
    }

    // 4. Unrelated/unknown 409 ----------------------------------------------------

    public function test_unrecognized_409_fails_for_manual_review_without_throwing(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response([
                'message' => 'Conflict: resource state mismatch',
            ], 409),
        ]);

        $order = $this->makeOrder($vendor);

        // No exception should propagate: an unrecognised 409 must not be
        // retried automatically (that would just resubmit and 409 again).
        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertSentCount(1);

        $order->refresh();
        $this->assertSame('failed', $order->external_fulfillment_status);
        $this->assertStringContainsString('Unrecognized 409', $order->external_fulfillment_last_error);
    }

    // 5. Already succeeded order ---------------------------------------------------

    public function test_already_succeeded_order_is_never_resubmitted(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake();

        $order = $this->makeOrder($vendor);
        Order::whereKey($order->id)->update([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_remote_reference' => 'DF-OLD',
            'external_fulfillment_completed_at' => now(),
        ]);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('DF-OLD', $order->refresh()->external_fulfillment_remote_reference);
    }

    // 6. Already delivered order ---------------------------------------------------

    public function test_already_delivered_order_is_never_resubmitted(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake();

        $order = $this->makeOrder($vendor, ['status' => 'Completed']);
        Order::whereKey($order->id)->update([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_remote_reference' => 'DF-OLD',
            'external_fulfillment_completed_at' => now(),
            'external_fulfillment_delivered_at' => now(),
        ]);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNothingSent();
    }

    // 7. Two concurrent/duplicate job executions -----------------------------------

    public function test_concurrent_job_executions_do_not_double_submit(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response(['reference' => 'DF-CONC'], 200),
        ]);

        $order = $this->makeOrder($vendor);

        // Simulate a second worker already holding the per-order lock.
        $contendingLock = Cache::lock(sprintf('lock:external-fulfillment:%d', $order->id), 600);
        $this->assertTrue($contendingLock->get());

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNothingSent();
        $this->assertNull($order->refresh()->external_fulfillment_status);

        $contendingLock->release();

        // Now the lock is free: the job proceeds normally.
        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertSentCount(1);
        $this->assertSame('succeeded', $order->refresh()->external_fulfillment_status);
    }

    // 8. Stable idempotency key across retries ---------------------------------------

    public function test_idempotency_key_is_stable_across_retries(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        $order = $this->makeOrder($vendor);

        // A single Http::fake() call covering both attempts: Laravel's
        // array-based fakes are additive across separate fake() calls (the
        // first-registered stub always wins), so a sequence is required to
        // return different responses to the same URL across two attempts.
        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::sequence()
                ->push(['error' => 'boom'], 500)
                ->push(['reference' => 'DF-RETRY'], 200),
        ]);

        try {
            (new ProcessExternalFulfillment($order->id))->handle();
            $this->fail('Expected a transient failure to throw for the queue to retry.');
        } catch (\RuntimeException $e) {
            // expected: preserves retry behaviour for genuine transient failures.
        }

        $order->refresh();
        $this->assertSame('failed', $order->external_fulfillment_status);
        $firstKey = $order->external_fulfillment_idempotency_key;
        $this->assertNotEmpty($firstKey);

        (new ProcessExternalFulfillment($order->id))->handle();

        $order->refresh();
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertSame($firstKey, $order->external_fulfillment_idempotency_key);

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $firstKey));
    }

    // 9. Accepted/processing order is polled instead of resubmitted -----------------
    // (distinct from #2: here the provider now reports delivery, and the local
    // order/transaction must be completed by the synchronizer, not by this job.)

    public function test_processing_order_with_remote_reference_is_synced_to_delivered(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableGigshub($vendor);

        $order = $this->makeOrder($vendor);
        Order::whereKey($order->id)->update([
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_remote_reference' => 'GH-ORDER-9',
            'external_fulfillment_provider_used' => 'gigshub',
        ]);

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response(['order' => ['status' => 'delivered']], 200),
        ]);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $order->refresh();
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertSame('Completed', $order->status);
        $this->assertNotNull($order->external_fulfillment_delivered_at);
    }

    // 10. Provider timeout after accepting request -----------------------------------

    public function test_connection_timeout_then_retry_succeeds_with_same_idempotency_key(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        $order = $this->makeOrder($vendor);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response(['reference' => 'DF-AFTER-TIMEOUT'], 200);
        });

        try {
            (new ProcessExternalFulfillment($order->id))->handle();
            $this->fail('Expected the connection failure to throw for the queue to retry.');
        } catch (\RuntimeException $e) {
        }

        $keyAfterFirstAttempt = $order->refresh()->external_fulfillment_idempotency_key;

        (new ProcessExternalFulfillment($order->id))->handle();

        $order->refresh();
        $this->assertSame(2, $calls);
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertSame('DF-AFTER-TIMEOUT', $order->external_fulfillment_remote_reference);
        $this->assertSame($keyAfterFirstAttempt, $order->external_fulfillment_idempotency_key);
    }

    // 11. Successful fulfillment cannot regress to failed/pending --------------------

    public function test_result_arriving_after_concurrent_resolution_does_not_regress_state(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        $order = $this->makeOrder($vendor);

        Http::fake(function () use ($order) {
            // Simulate a webhook resolving the order to "succeeded" while
            // our provider request is still in flight.
            Order::whereKey($order->id)->update([
                'external_fulfillment_status' => 'succeeded',
                'external_fulfillment_remote_reference' => 'WEBHOOK-WON-THE-RACE',
                'external_fulfillment_completed_at' => now(),
            ]);

            return Http::response(['error' => 'unrelated failure'], 500);
        });

        // Must not throw: the failure is stale by the time it is applied.
        (new ProcessExternalFulfillment($order->id))->handle();

        $order->refresh();
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertSame('WEBHOOK-WON-THE-RACE', $order->external_fulfillment_remote_reference);
        $this->assertNotSame('failed', $order->external_fulfillment_status);
    }

    // 12. No duplicate transaction or vendor wallet credit ----------------------------

    public function test_fulfillment_retries_never_touch_transactions_or_wallet_balance(): void
    {
        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
            'wallet_balance' => 100.00,
        ]);
        $this->enableDatafyhub($vendor);

        $order = $this->makeOrder($vendor);
        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 10.00,
            'commission_amount' => 0.20,
            'vendor_earning' => 9.80,
            'payment_status' => 'successful',
            'timestamp' => now(),
            'payment_type' => 'order',
        ]);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response(['reference' => 'DF-WALLET'], 200),
        ]);

        (new ProcessExternalFulfillment($order->id))->handle();
        // Second run should be a pure no-op (already succeeded).
        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertSentCount(1);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertEquals(100.00, $vendor->fresh()->wallet_balance);
    }

    // 13. Historical administratively closed orders cannot be resubmitted -------------

    public function test_administratively_closed_order_is_never_resubmitted(): void
    {
        $vendor = Vendor::factory()->create(['affiliate_vendor_id' => null]);
        $this->enableDatafyhub($vendor);

        Http::fake();

        $order = $this->makeOrder($vendor);
        Order::whereKey($order->id)->update([
            // Deliberately NOT 'succeeded' — proves the reconciliation_note
            // guard works independently of external_fulfillment_status.
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_remote_reference' => 'DF-LEGACY',
            'reconciliation_note' => 'Fulfillment closed administratively on 2026-06-01 (fulfillment:close-paid-legacy --before=2026-01-01). Payment succeeded; administrator confirmed this order was already delivered.',
        ]);

        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('processing', $order->refresh()->external_fulfillment_status);
    }
}
