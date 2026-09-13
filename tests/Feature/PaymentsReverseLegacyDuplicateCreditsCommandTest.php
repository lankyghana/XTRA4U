<?php

namespace Tests\Feature;

use App\Console\Commands\PaymentsReverseLegacyDuplicateCredits;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage for `payments:reverse-legacy-duplicate-credits` — reversing the
 * duplicate vendor wallet credits created when 169 already-completed
 * historical orders had `PaymentService::completeOrder()` run against them a
 * second time on the incident date.
 *
 * Throughout: the incident date is pinned via Carbon::setTestNow() so
 * "on the incident date" is deterministic regardless of when the suite runs.
 */
class PaymentsReverseLegacyDuplicateCreditsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const INCIDENT_DATE = '2026-09-13';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::INCIDENT_DATE.' 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vendor(float $walletBalance): Vendor
    {
        return Vendor::factory()->create(['wallet_balance' => $walletBalance]);
    }

    /**
     * An "incident" order: created well before the incident date, but its
     * `payment_completed_at` was stamped today (the re-completion bug).
     *
     * @param  array<string,mixed>  $overrides
     */
    private function incidentOrder(Vendor $vendor, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => 'MTN 1GB',
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'LEGACY-DUP-'.uniqid(),
            'payment_gateway' => 'paystack',
            'payment_completed_at' => now(),
        ], $overrides));

        // created_at is not mass-assignable — backdate it via a raw update so
        // this order genuinely predates the incident.
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $overrides['created_at'] ?? now()->subMonths(6),
        ]);

        return $order->fresh();
    }

    private function transaction(Order $order, Vendor $vendor, float $amount, float $commission, float $vendorEarning, string $paymentStatus = 'successful'): Transaction
    {
        return Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => $amount,
            'commission_amount' => $commission,
            'vendor_earning' => $vendorEarning,
            'payment_status' => $paymentStatus,
            'transactionable_type' => Order::class,
            'transactionable_id' => $order->id,
            'payment_type' => 'order',
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Dry-run makes zero writes
    // -----------------------------------------------------------------

    public function test_dry_run_makes_zero_writes(): void
    {
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 20.00, 0.40, 19.60);

        $this->artisan('payments:reverse-legacy-duplicate-credits', ['--date' => self::INCIDENT_DATE])
            ->assertExitCode(0);

        $this->assertEquals(500.00, (float) $vendor->fresh()->wallet_balance);
        $this->assertNull($order->fresh()->duplicate_credit_reversed_at);
        $this->assertDatabaseCount('wallet_ledgers', 0);
    }

    public function test_execute_flag_is_required_to_write_changes(): void
    {
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 20.00, 0.40, 19.60);

        // No --execute — must behave exactly like dry-run.
        $this->artisan('payments:reverse-legacy-duplicate-credits', ['--date' => self::INCIDENT_DATE])
            ->assertExitCode(0);

        $this->assertEquals(500.00, (float) $vendor->fresh()->wallet_balance);
    }

    public function test_date_option_is_mandatory(): void
    {
        $this->artisan('payments:reverse-legacy-duplicate-credits', ['--execute' => true])
            ->assertExitCode(1);

        $this->artisan('payments:reverse-legacy-duplicate-credits', ['--date' => 'not-a-date'])
            ->assertExitCode(1);
    }

    // -----------------------------------------------------------------
    // 2. Exactly the identified duplicate amount is reversed
    // -----------------------------------------------------------------

    public function test_exact_duplicate_amount_is_reversed(): void
    {
        // 400 historical (legitimate, already there before today) + 100 duplicate = 500.
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(400.00, (float) $vendor->fresh()->wallet_balance);
        $this->assertNotNull($order->fresh()->duplicate_credit_reversed_at);
    }

    // -----------------------------------------------------------------
    // 3. Legitimate same-day earnings are untouched
    // -----------------------------------------------------------------

    public function test_legitimate_same_day_earnings_are_untouched(): void
    {
        // Same vendor: one OLD (incident) order duplicated today, plus one
        // genuinely NEW order created and completed today (a real same-day
        // sale, not part of the incident — created_at is NOT before the
        // incident date, so it must never be touched).
        $vendor = $this->vendor(400.00 + 100.00 + 50.00); // historical + duplicate + legitimate-today

        $incident = $this->incidentOrder($vendor);
        $this->transaction($incident, $vendor, 102.04, 2.04, 100.00);

        $legitimateToday = Order::create([
            'recipient_phone_number' => '0244111111',
            'mobile_money_number' => '0244111111',
            'service_purchased' => 'MTN 2GB',
            'amount_paid' => 51.02,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TODAY-LEGIT-'.uniqid(),
            'payment_gateway' => 'paystack',
            'payment_completed_at' => now(),
            // created_at defaults to now() — today, NOT before the incident date.
        ]);
        $this->transaction($legitimateToday, $vendor, 51.02, 1.02, 50.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        // Only the 100 duplicate came off; the 50 legitimate same-day earning survives.
        $this->assertEquals(450.00, (float) $vendor->fresh()->wallet_balance);
        $this->assertNotNull($incident->fresh()->duplicate_credit_reversed_at);
        $this->assertNull($legitimateToday->fresh()->duplicate_credit_reversed_at);
    }

    // -----------------------------------------------------------------
    // 4. Reseller owner + reseller credits are both reversed
    // -----------------------------------------------------------------

    public function test_reseller_owner_and_reseller_credits_are_both_reversed(): void
    {
        $owner = $this->vendor(1000.00); // 900 historical + 100 duplicate
        $reseller = $this->vendor(250.00); // 200 historical + 50 duplicate

        $order = $this->incidentOrder($owner, [
            'is_reseller_order' => true,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_earning' => 100.00,
            'reseller_earning' => 50.00,
        ]);
        $this->transaction($order, $owner, 102.04, 2.04, 100.00);
        $this->transaction($order, $reseller, 51.02, 1.02, 50.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(900.00, (float) $owner->fresh()->wallet_balance);
        $this->assertEquals(200.00, (float) $reseller->fresh()->wallet_balance);
        $this->assertNotNull($order->fresh()->duplicate_credit_reversed_at);

        $this->assertDatabaseHas('wallet_ledgers', ['vendor_id' => $owner->id, 'amount' => 100.00, 'type' => 'debit']);
        $this->assertDatabaseHas('wallet_ledgers', ['vendor_id' => $reseller->id, 'amount' => 50.00, 'type' => 'debit']);
    }

    // -----------------------------------------------------------------
    // 5. Regular-order credit is reversed correctly (commission excluded)
    // -----------------------------------------------------------------

    public function test_regular_order_credit_is_reversed_correctly(): void
    {
        $vendor = $this->vendor(300.00); // 210.80 historical + 89.20 duplicate
        $order = $this->incidentOrder($vendor);
        // amount=91.02, commission=1.82, vendor_earning=89.20 — reversal must use vendor_earning, not amount.
        $this->transaction($order, $vendor, 91.02, 1.82, 89.20);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(210.80, (float) $vendor->fresh()->wallet_balance);
    }

    // -----------------------------------------------------------------
    // 6. Transaction/payment/order statuses are unchanged
    // -----------------------------------------------------------------

    public function test_transaction_payment_and_order_statuses_are_unchanged(): void
    {
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor, ['status' => 'Completed', 'payment_reference' => 'STATUS-PRESERVE']);
        $transaction = $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $freshOrder = $order->fresh();
        $this->assertSame('Completed', $freshOrder->status);
        $this->assertSame('paid', $freshOrder->payment_status);
        $this->assertSame('STATUS-PRESERVE', $freshOrder->payment_reference);
        $this->assertSame('successful', $transaction->fresh()->payment_status);
        $this->assertEquals(100.00, (float) $transaction->fresh()->vendor_earning, 'Transaction history must be preserved, not rewritten.');
    }

    // -----------------------------------------------------------------
    // 7. No gateway/provider HTTP calls
    // -----------------------------------------------------------------

    public function test_no_external_http_requests_occur(): void
    {
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        Http::fake();

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // 8. No fulfillment jobs dispatched
    // -----------------------------------------------------------------

    public function test_no_fulfillment_jobs_are_dispatched(): void
    {
        Queue::fake();

        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // 9. Running the command twice does not reverse twice
    // -----------------------------------------------------------------

    public function test_running_command_twice_does_not_reverse_twice(): void
    {
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(400.00, (float) $vendor->fresh()->wallet_balance);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(400.00, (float) $vendor->fresh()->wallet_balance, 'A second run must not subtract money twice.');
        $this->assertDatabaseCount('wallet_ledgers', 1);
    }

    // -----------------------------------------------------------------
    // 10. Insufficient-balance vendor is safely skipped / manual review
    // -----------------------------------------------------------------

    public function test_insufficient_balance_vendor_is_skipped_for_manual_review(): void
    {
        // Vendor already withdrew/spent most of the duplicate: only 30 left, but 100 is owed.
        $vendor = $this->vendor(30.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])
            ->expectsOutputToContain('INSUFFICIENT BALANCE')
            ->assertExitCode(0);

        // Untouched — never driven negative, never partially reversed.
        $this->assertEquals(30.00, (float) $vendor->fresh()->wallet_balance);
        $this->assertNull($order->fresh()->duplicate_credit_reversed_at);
        $this->assertDatabaseCount('wallet_ledgers', 0);
    }

    public function test_reseller_order_is_fully_skipped_if_either_leg_is_insufficient(): void
    {
        $owner = $this->vendor(1000.00); // plenty
        $reseller = $this->vendor(10.00); // insufficient for the 50 owed

        $order = $this->incidentOrder($owner, [
            'is_reseller_order' => true,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_earning' => 100.00,
            'reseller_earning' => 50.00,
        ]);
        $this->transaction($order, $owner, 102.04, 2.04, 100.00);
        $this->transaction($order, $reseller, 51.02, 1.02, 50.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        // Neither leg touched — an order is never partially reversed.
        $this->assertEquals(1000.00, (float) $owner->fresh()->wallet_balance);
        $this->assertEquals(10.00, (float) $reseller->fresh()->wallet_balance);
        $this->assertNull($order->fresh()->duplicate_credit_reversed_at);
        $this->assertDatabaseCount('wallet_ledgers', 0);
    }

    // -----------------------------------------------------------------
    // 11. Exact audit/reversal record is created
    // -----------------------------------------------------------------

    public function test_exact_audit_reversal_record_is_created(): void
    {
        $vendor = $this->vendor(500.00);
        $order = $this->incidentOrder($vendor);
        $this->transaction($order, $vendor, 102.04, 2.04, 100.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $ledger = WalletLedger::where('vendor_id', $vendor->id)->sole();

        $this->assertSame('debit', $ledger->type);
        $this->assertSame(PaymentsReverseLegacyDuplicateCredits::LEDGER_SOURCE, $ledger->source);
        $this->assertEquals(100.00, (float) $ledger->amount);
        $this->assertEquals(400.00, (float) $ledger->balance_after);
        $this->assertSame($order->id, $ledger->metadata['order_id']);
    }

    // -----------------------------------------------------------------
    // 12. Total wallet decrements equal total recorded reversals
    // -----------------------------------------------------------------

    public function test_total_wallet_decrements_equal_total_recorded_reversals(): void
    {
        $vendorA = $this->vendor(500.00);
        $orderA = $this->incidentOrder($vendorA);
        $this->transaction($orderA, $vendorA, 102.04, 2.04, 100.00);

        $owner = $this->vendor(1000.00);
        $reseller = $this->vendor(250.00);
        $orderB = $this->incidentOrder($owner, [
            'is_reseller_order' => true,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_earning' => 100.00,
            'reseller_earning' => 50.00,
        ]);
        $this->transaction($orderB, $owner, 102.04, 2.04, 100.00);
        $this->transaction($orderB, $reseller, 51.02, 1.02, 50.00);

        $balancesBefore = [
            $vendorA->id => (float) $vendorA->wallet_balance,
            $owner->id => (float) $owner->wallet_balance,
            $reseller->id => (float) $reseller->wallet_balance,
        ];

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $totalDecrement = ($balancesBefore[$vendorA->id] - (float) $vendorA->fresh()->wallet_balance)
            + ($balancesBefore[$owner->id] - (float) $owner->fresh()->wallet_balance)
            + ($balancesBefore[$reseller->id] - (float) $reseller->fresh()->wallet_balance);

        $totalLedgerReversals = (float) WalletLedger::where('source', PaymentsReverseLegacyDuplicateCredits::LEDGER_SOURCE)->sum('amount');

        $this->assertEquals(250.00, $totalDecrement, '100 + 100 + 50 across both orders.');
        $this->assertEqualsWithDelta($totalDecrement, $totalLedgerReversals, 0.001);
    }

    // -----------------------------------------------------------------
    // Already-fulfilled / no-op edge cases
    // -----------------------------------------------------------------

    public function test_orders_outside_the_incident_window_are_never_touched(): void
    {
        $vendor = $this->vendor(500.00);

        // Created ON the incident date (not before it) — not a historical order.
        $notOld = $this->incidentOrder($vendor, ['created_at' => Carbon::parse(self::INCIDENT_DATE.' 01:00:00')]);
        $this->transaction($notOld, $vendor, 102.04, 2.04, 100.00);

        // Completed on a different day entirely — not part of this incident.
        $differentDay = $this->incidentOrder($vendor, ['payment_completed_at' => now()->subDays(3)]);
        $this->transaction($differentDay, $vendor, 51.02, 1.02, 50.00);

        $this->artisan('payments:reverse-legacy-duplicate-credits', [
            '--date' => self::INCIDENT_DATE,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(500.00, (float) $vendor->fresh()->wallet_balance);
        $this->assertNull($notOld->fresh()->duplicate_credit_reversed_at);
        $this->assertNull($differentDay->fresh()->duplicate_credit_reversed_at);
    }
}
