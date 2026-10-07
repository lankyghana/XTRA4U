<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Order;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Models\WalletLedger;
use App\Services\PaymentService;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillCommissionService;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class UtilityBillFulfillmentTest extends UtilityBillTestCase
{
    private function svc(): UtilityBillFulfillmentService
    {
        return app(UtilityBillFulfillmentService::class);
    }

    private function payCount(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/utilities/pay'))->count();
    }

    // ---- payment boundary ----------------------------------------------

    public function test_unproven_payment_never_reaches_the_provider(): void
    {
        $u = $this->makeOrder();
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);

        // Someone just flips the status column: not a trusted payment.
        Order::whereKey($u->order_id)->update(['payment_status' => 'paid']);
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::QUEUED])->save();

        $this->assertStringStartsWith('skipped:not_paid', $this->svc()->submit($u->id));
        $this->assertSame(0, $this->payCount());
    }

    public function test_canonical_completion_queues_and_submits_exactly_one_provider_payment(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);

        $u = $this->makeOrder(['paid' => true]);

        $this->assertSame(1, $this->payCount());
        $u->refresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);
        $this->assertSame('UTIL-DSTV-aaa111', $u->provider_order_reference);
        $this->assertSame('XU-'.$u->public_ref.'-A1', $u->provider_request_reference);
        // Separate references, and our request reference was what we sent.
        $this->assertNotSame($u->provider_request_reference, $u->provider_order_reference);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['reference'] === 'XU-'.$u->public_ref.'-A1');

        $order = Order::find($u->order_id);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNull($order->vendor_id);
        $this->assertSame(0, \App\Models\Transaction::count());
    }

    public function test_completing_payment_twice_does_not_fulfil_twice(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);

        app(PaymentService::class)->completeOrder(Order::find($u->order_id));
        $this->svc()->submit($u->id);
        $this->svc()->submit($u->id);

        $this->assertSame(1, $this->payCount());
    }

    // ---- idempotency ----------------------------------------------------

    public function test_timeout_retries_with_the_same_reference(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('timed out')]);
        $u = $this->makeOrder(['paid' => true]);
        $u->refresh();

        $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
        $ref = $u->provider_request_reference;
        $this->assertNotNull($ref);
        $this->assertNull($u->provider_order_reference);

        // Provider had actually processed it: the replay returns the original order.
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => true, 'data' => [
            'reference' => 'UTIL-DSTV-aaa111', 'order_id' => 'uuid-1', 'status' => 'pending', 'already_processed' => true]])]);
        $this->svc()->submit($u->id, force: true);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['reference'] === $ref);
        $u->refresh();
        $this->assertSame($ref, $u->provider_request_reference);
        $this->assertSame('UTIL-DSTV-aaa111', $u->provider_order_reference);
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);
    }

    public function test_a_claimed_order_cannot_be_submitted_by_a_second_worker(): void
    {
        $u = $this->makeOrder(['paid' => true]); // submitted already (default: no fake -> connection fails -> queued)
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u->refresh()->forceFill(['fulfillment_status' => FulfillmentStatus::SUBMITTING, 'claim_token' => 'x', 'claimed_at' => now()])->save();

        $this->assertSame('skipped:state', $this->svc()->submit($u->id, force: true));
        $this->assertSame(0, $this->payCount());
    }

    public function test_a_stale_claim_is_recovered_with_the_same_reference(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('x')]);
        $u = $this->makeOrder(['paid' => true]);
        $ref = $u->refresh()->provider_request_reference;
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::SUBMITTING, 'claim_token' => 'dead', 'claimed_at' => now()->subHour()])->save();

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $this->svc()->submit($u->id, force: true);

        Http::assertSent(fn (Request $r) => $r['reference'] === $ref);
        $this->assertSame(1, $this->payCount());
    }

    public function test_scheduler_requeues_and_dispatches_without_double_paying(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('x')]);
        $u = $this->makeOrder(['paid' => true]);
        $u->refresh()->forceFill(['next_submit_at' => now()->subMinute()])->save();

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        app(UtilityBillSweeper::class)->run();
        app(UtilityBillSweeper::class)->run();

        $this->assertSame(1, $this->payCount());
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->refresh()->fulfillment_status);
    }

    // ---- provider failures ---------------------------------------------

    public function test_insufficient_provider_wallet_keeps_the_customer_payment_paid_and_is_recoverable(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400)]);
        $u = $this->makeOrder(['paid' => true]);
        $u->refresh();

        $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);
        $this->assertSame('insufficient_balance', $u->last_error_code);
        $this->assertSame('paid', Order::find($u->order_id)->payment_status);
        $this->assertSame('0.00', (string) $u->vendor->fresh()->wallet_balance);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'utility_bill_attention']);

        // Admin funds the provider wallet and retries: same reference, now succeeds.
        $ref = $u->provider_request_reference;
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $res = $this->svc()->adminRetry($u->id, ['id' => 1, 'email' => 'a@x.test']);

        $this->assertTrue($res['ok']);
        $u->refresh();
        $this->assertSame($ref, $u->provider_request_reference);
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);
    }

    public function test_rate_limited_pay_is_requeued_with_the_same_reference(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false], 429)]);
        $u = $this->makeOrder(['paid' => true]);
        $u->refresh();

        $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
        $this->assertSame('rate_limited', $u->last_error_code);
        $this->assertNotNull($u->provider_request_reference);
    }

    public function test_service_disabled_503_needs_attention_not_failure(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false], 503)]);
        $u = $this->makeOrder(['paid' => true])->refresh();

        $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);
        $this->assertSame('paid', Order::find($u->order_id)->payment_status);
    }

    public function test_admin_retry_refuses_completed_and_in_flight_orders(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);

        $res = $this->svc()->adminRetry($u->id, ['id' => 1]);
        $this->assertFalse($res['ok']);
        $this->assertSame(1, $this->payCount());

        $u->forceFill(['fulfillment_status' => FulfillmentStatus::COMPLETED])->save();
        $this->assertFalse($this->svc()->adminRetry($u->id, ['id' => 1])['ok']);
        $this->assertSame(1, $this->payCount());
    }

    public function test_new_provider_attempt_requires_explicit_confirmation_and_a_closed_attempt(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded'))]);
        $this->svc()->syncStatus($u->id);
        $u->refresh();
        $this->assertSame(FulfillmentStatus::PROVIDER_REFUNDED, $u->fulfillment_status);

        $this->assertFalse($this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: false)['ok']);

        $this->fake([
            self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded')),
            self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-bbb222')),
        ]);
        $res = $this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true, reason: 'Provider refunded; customer still needs it');
        $this->assertTrue($res['ok']);
        $u->refresh();
        $this->assertSame(2, $u->provider_attempt);
        $this->assertSame('XU-'.$u->public_ref.'-A2', $u->provider_request_reference);
        $this->assertSame('UTIL-DSTV-bbb222', $u->provider_order_reference);
    }

    // ---- status + commission -------------------------------------------

    public function test_pending_processing_failed_and_refunded_earn_no_commission(): void
    {
        foreach (['pending', 'processing', 'failed', 'refunded'] as $status) {
            $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-'.$status))]);
            $u = $this->makeOrder(['paid' => true]);
            $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody($status, 'UTIL-DSTV-'.$status))]);
            $this->svc()->syncStatus($u->id);

            $this->assertSame('pending', $u->refresh()->commission_status, $status);
            $this->assertNull($u->commission_wallet_ledger_id);
            $this->assertSame('0.00', (string) $u->vendor->fresh()->wallet_balance, $status);
        }
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_completed_credits_the_frozen_commission_exactly_once(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true, 'amount' => '100.00', 'commission_type' => 'percentage', 'commission_value' => '1.0000']);
        $vendor = $u->vendor;

        // Admin later changes the rate: the old order must not care.
        \App\Models\UtilityBillerConfig::create(['biller_key' => 'dstv', 'is_enabled' => true, 'commission_type' => 'percentage', 'commission_value' => '5']);

        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', commission: 1.3))]);
        $this->svc()->syncStatus($u->id);
        // Repeats: poll duplication, queue retry, admin refresh.
        $u->refresh();
        $this->svc()->afterStatusChange($u->id);
        app(UtilityBillCommissionService::class)->settle($u->id);
        app(UtilityBillCommissionService::class)->settle($u->id);

        $u->refresh();
        $this->assertSame(FulfillmentStatus::COMPLETED, $u->fulfillment_status);
        $this->assertSame('credited', $u->commission_status);
        $this->assertSame('1.00', (string) $u->commission_amount);
        $this->assertSame('1.30', (string) $u->provider_commission_earned);   // provider -> XTRA4U, kept separate
        $this->assertSame('1.00', (string) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, WalletLedger::where('source', 'utility_bill_commission')->count());

        $ledger = WalletLedger::first();
        $this->assertSame($ledger->id, $u->commission_wallet_ledger_id);
        $this->assertSame('1.00', number_format($ledger->amount, 2));
        $this->assertSame(1, \App\Models\VendorNotification::where('type', 'utility_bill_commission')->count());
        $this->assertSame('Completed', Order::find($u->order_id)->status);
    }

    public function test_fixed_commission_and_direct_sales(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-fixed'))]);
        $fixed = $this->makeOrder(['paid' => true, 'amount' => '65.00', 'commission_type' => 'fixed', 'commission_value' => '1.0000']);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-direct'))]);
        $direct = $this->makeOrder(['paid' => true, 'vendor' => null]);

        foreach ([$fixed, $direct] as $u) {
            $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', $u->fresh()->provider_order_reference))]);
            $this->svc()->syncStatus($u->id);
        }

        $this->assertSame('credited', $fixed->refresh()->commission_status);
        $this->assertSame('1.00', (string) $fixed->vendor->fresh()->wallet_balance);
        $this->assertSame('none', $direct->refresh()->commission_status);
        $this->assertNull($direct->vendor_id);
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_commission_is_refused_when_the_payment_is_not_proven(): void
    {
        $u = $this->makeOrder();
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::COMPLETED])->save();

        $this->assertSame(UtilityBillCommissionService::NOT_PAID, app(UtilityBillCommissionService::class)->settle($u->id));
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_terminal_orders_never_regress_on_a_contradicting_status(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed'))]);
        $this->svc()->syncStatus($u->id);

        $this->svc()->applyProviderStatus($u->id, new \App\Services\UtilityBills\Data\StatusResult('UTIL-DSTV-aaa111', 'refunded', 'paid', null, null, null, null));

        $this->assertSame(FulfillmentStatus::COMPLETED, $u->refresh()->fulfillment_status);
        $this->assertDatabaseHas('utility_bill_events', ['utility_bill_order_id' => $u->id, 'kind' => 'status_contradiction']);
    }

    public function test_a_status_for_a_different_provider_order_is_ignored(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);

        $this->svc()->applyProviderStatus($u->id, new \App\Services\UtilityBills\Data\StatusResult('UTIL-OTHER-999', 'completed', 'paid', null, null, null, null));

        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->refresh()->fulfillment_status);
    }

    public function test_unrecognised_provider_status_changes_nothing(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);

        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('success'))]);
        $this->svc()->syncStatus($u->id);

        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->refresh()->fulfillment_status);
    }
}
