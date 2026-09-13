<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalFulfillment;
use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResultCheckerOrder;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\Admin\PaymentHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage for `payments:close-legacy` — the production legacy-order
 * cleanup command. Every test here exists to prove one of the safety
 * properties the task brief demands: dry-run-by-default, cutoff is
 * mandatory, current records are never touched, financial data is never
 * altered, and — most importantly — a closed record can never again be
 * picked up by reconciliation, Payment Health, or external fulfillment.
 */
class PaymentsCloseLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    protected function legacyOrder(string $reference, \Carbon\Carbon $createdAt, array $overrides = []): Order
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => 20.00,
            'is_active' => true,
        ]);

        $order = Order::create(array_merge([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => $reference,
        ], $overrides));

        DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);

        return $order->fresh();
    }

    protected function legacyAfa(string $reference, \Carbon\Carbon $createdAt): AfaRegistration
    {
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 10]);

        $afa = AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'full_name' => 'Test User',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0550000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Engineer',
            'amount' => 10,
            'vendor_price' => 10,
            'platform_commission' => 0.2,
            'vendor_earning' => 9.8,
            'reseller_earning' => 0,
            'is_reseller_order' => false,
            'status' => AfaRegistration::STATUS_PENDING,
            'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'reference' => AfaRegistration::generateReference(),
            'payment_reference' => $reference,
            'payment_gateway' => 'paystack',
        ]);

        DB::table('afa_registrations')->where('id', $afa->id)->update(['created_at' => $createdAt]);

        return $afa->fresh();
    }

    protected function legacyResultChecker(string $reference, \Carbon\Carbon $createdAt, string $status = 'pending_payment'): ResultCheckerOrder
    {
        $order = ResultCheckerOrder::factory()->create([
            'payment_reference' => $reference,
            'payment_gateway' => 'paystack',
            'status' => $status,
            'total_price' => 100.00,
            'unit_price' => 100.00,
        ]);

        DB::table('result_checker_orders')->where('id', $order->id)->update(['created_at' => $createdAt]);

        return $order->fresh();
    }

    protected function legacyUssd(string $reference, \Carbon\Carbon $createdAt, string $status = UssdSubscription::STATUS_PENDING_PAYMENT): UssdSubscription
    {
        $vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $subscription = UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => $status,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => $reference,
            'payment_gateway' => 'paystack',
        ]);

        DB::table('ussd_subscriptions')->where('id', $subscription->id)->update(['created_at' => $createdAt]);

        return $subscription->fresh();
    }

    protected function legacyWalletTopup(string $reference, \Carbon\Carbon $createdAt, string $status = 'initiated'): WalletTopup
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        $topup = WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendor->id,
            'amount' => 60.00,
            'status' => $status,
            'payment_gateway' => 'paystack',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        DB::table('wallet_topups')->where('id', $topup->id)->update(['created_at' => $createdAt]);

        return $topup->fresh();
    }

    // -----------------------------------------------------------------
    // 1. dry-run modifies nothing
    // -----------------------------------------------------------------

    public function test_dry_run_modifies_nothing(): void
    {
        $order = $this->legacyOrder('CLOSE-DRY-1', now()->subMonths(6));
        $afa = $this->legacyAfa('CLOSE-DRY-2', now()->subMonths(6));

        $this->artisan('payments:close-legacy', ['--before' => now()->subMonths(1)->toDateString()])
            ->assertExitCode(0);

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->reconciliation_note);

        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $afa->fresh()->payment_status);
        $this->assertNull($afa->fresh()->reconciliation_note);
    }

    // -----------------------------------------------------------------
    // 2. --execute is required to write anything
    // -----------------------------------------------------------------

    public function test_execute_flag_is_required_to_write_changes(): void
    {
        $order = $this->legacyOrder('CLOSE-NOEXEC', now()->subMonths(6));

        // No --execute supplied at all — must behave exactly like dry-run.
        $this->artisan('payments:close-legacy', ['--before' => now()->subMonths(1)->toDateString()])
            ->assertExitCode(0);

        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // 3. cutoff is mandatory
    // -----------------------------------------------------------------

    public function test_before_option_is_mandatory(): void
    {
        $this->artisan('payments:close-legacy', ['--execute' => true])
            ->assertExitCode(1);

        $this->artisan('payments:close-legacy', ['--before' => 'not-a-date'])
            ->assertExitCode(1);
    }

    // -----------------------------------------------------------------
    // 4. records newer than cutoff are untouched
    // -----------------------------------------------------------------

    public function test_records_on_or_after_cutoff_are_never_touched(): void
    {
        $cutoff = now()->subMonths(1)->startOfDay();

        $old = $this->legacyOrder('CLOSE-OLD', (clone $cutoff)->subDay());
        $onCutoff = $this->legacyOrder('CLOSE-ON-CUTOFF', clone $cutoff);
        $new = $this->legacyOrder('CLOSE-NEW', now());

        $this->artisan('payments:close-legacy', [
            '--before' => $cutoff->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertSame('failed', $old->fresh()->payment_status, 'Strictly-before-cutoff record must be closed.');
        $this->assertSame('unpaid', $onCutoff->fresh()->payment_status, 'A record created exactly on the cutoff must never change.');
        $this->assertSame('unpaid', $new->fresh()->payment_status, 'A current record must never change.');
    }

    // -----------------------------------------------------------------
    // 5. terminal records are untouched
    // -----------------------------------------------------------------

    public function test_already_terminal_records_are_untouched(): void
    {
        $failed = $this->legacyOrder('CLOSE-ALREADY-FAILED', now()->subMonths(6), [
            'payment_status' => 'failed',
            'status' => 'Failed',
        ]);

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertSame('failed', $failed->fresh()->payment_status);
        $this->assertNull($failed->fresh()->reconciliation_note, 'An already-terminal record must not be rewritten by this command.');
    }

    // -----------------------------------------------------------------
    // 6 & 7. financial amounts and payment references are untouched
    // -----------------------------------------------------------------

    public function test_financial_amounts_and_payment_references_are_preserved(): void
    {
        $order = $this->legacyOrder('CLOSE-FINANCIAL', now()->subMonths(6));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->payment_status);
        $this->assertEquals(20.00, (float) $fresh->amount_paid, 'Monetary amount must never be altered.');
        $this->assertSame('CLOSE-FINANCIAL', $fresh->payment_reference, 'Payment reference must be preserved.');
        $this->assertNotNull($fresh->id, 'The record itself must never be deleted.');
    }

    // -----------------------------------------------------------------
    // 8. wallet balances are untouched
    // -----------------------------------------------------------------

    public function test_wallet_balances_are_untouched(): void
    {
        $topup = $this->legacyWalletTopup('CLOSE-WALLET', now()->subMonths(6));
        $vendor = $topup->vendor;
        $vendor->update(['wallet_balance' => 42.50]);

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertSame('failed', $topup->fresh()->status);
        $this->assertEquals(42.50, (float) $vendor->fresh()->wallet_balance, 'Wallet balance must never be touched by this command.');
    }

    // -----------------------------------------------------------------
    // 9 & 10. no gateway or fulfillment provider HTTP requests
    // -----------------------------------------------------------------

    public function test_no_gateway_or_fulfillment_provider_http_requests_occur(): void
    {
        $this->makePaystackConfig();
        $this->legacyOrder('CLOSE-NO-HTTP-1', now()->subMonths(6));
        $this->legacyAfa('CLOSE-NO-HTTP-2', now()->subMonths(6));
        $this->legacyResultChecker('CLOSE-NO-HTTP-3', now()->subMonths(6));
        $this->legacyUssd('CLOSE-NO-HTTP-4', now()->subMonths(6));
        $this->legacyWalletTopup('CLOSE-NO-HTTP-5', now()->subMonths(6));

        Http::fake(); // Any HTTP call at all fails the assertion below.

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // 11. no fulfillment jobs are dispatched
    // -----------------------------------------------------------------

    public function test_no_fulfillment_jobs_are_dispatched(): void
    {
        Queue::fake();

        $this->legacyOrder('CLOSE-NO-JOBS', now()->subMonths(6));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // 12. closed records disappear from reconciliation eligibility
    // -----------------------------------------------------------------

    public function test_closed_records_disappear_from_reconciliation_eligibility(): void
    {
        $this->makePaystackConfig();
        $order = $this->legacyOrder('CLOSE-RECON', now()->subMonths(6));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Http::fake(); // payments:reconcile must never even try to verify it.

        $this->artisan('payments:reconcile')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // 13. closed records disappear from pending Payment Health counts
    // -----------------------------------------------------------------

    public function test_closed_records_disappear_from_pending_payment_health_counts(): void
    {
        $this->legacyOrder('CLOSE-HEALTH', now()->subMonths(6));

        $before = app(PaymentHealthService::class)->summary();
        $this->assertGreaterThan(0, $before['totals']['pending_over_24h']);

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $after = app(PaymentHealthService::class)->summary();
        $this->assertSame(0, $after['totals']['pending_over_24h']);
        $this->assertSame(0, $after['totals']['reconciliation_due']);
    }

    // -----------------------------------------------------------------
    // 14. closed records cannot subsequently trigger fulfillment
    // -----------------------------------------------------------------

    public function test_closed_order_cannot_subsequently_trigger_fulfillment(): void
    {
        $order = $this->legacyOrder('CLOSE-FULFILL', now()->subMonths(6));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        Http::fake();

        // Even if something were to (incorrectly) dispatch the fulfillment
        // job for this order id, the job's own payment_status guard must
        // refuse it — it is no longer 'paid'/'completed'.
        (new ProcessExternalFulfillment($order->id))->handle();

        Http::assertNothingSent();
        $this->assertNull($order->fresh()->external_fulfillment_status);
    }

    // -----------------------------------------------------------------
    // 15. repeated execution is idempotent
    // -----------------------------------------------------------------

    public function test_repeated_execution_is_idempotent(): void
    {
        $order = $this->legacyOrder('CLOSE-IDEMPOTENT', now()->subMonths(6));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $afterFirstRun = $order->fresh();
        $this->assertSame('failed', $afterFirstRun->payment_status);
        $noteAfterFirstRun = $afterFirstRun->reconciliation_note;
        $updatedAtAfterFirstRun = $afterFirstRun->updated_at;

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $afterSecondRun = $order->fresh();
        $this->assertSame('failed', $afterSecondRun->payment_status);
        $this->assertSame($noteAfterFirstRun, $afterSecondRun->reconciliation_note);
        $this->assertTrue(
            $updatedAtAfterFirstRun->equalTo($afterSecondRun->updated_at),
            'A second run must write zero already-closed records — updated_at must not change.'
        );
    }

    // -----------------------------------------------------------------
    // 16. ambiguous paid-but-unfulfilled records are skipped
    // -----------------------------------------------------------------

    public function test_ambiguous_paid_but_unfulfilled_order_is_skipped(): void
    {
        $paidUnfulfilled = $this->legacyOrder('CLOSE-AMBIGUOUS', now()->subMonths(6), [
            'payment_status' => 'paid',
            'status' => 'Processing',
        ]);

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $paidUnfulfilled->fresh();
        $this->assertSame('paid', $fresh->payment_status, 'A paid-but-unfulfilled order must never be modified by this command.');
        $this->assertSame('Processing', $fresh->status);
        $this->assertNull($fresh->reconciliation_note);
    }

    public function test_ambiguous_paid_but_unactivated_ussd_subscription_is_skipped(): void
    {
        // status=PAID means the gateway payment already succeeded and only
        // activation is outstanding — this must never be flipped to failed.
        $subscription = $this->legacyUssd('CLOSE-USSD-AMBIGUOUS', now()->subMonths(6), UssdSubscription::STATUS_PAID);

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $subscription->fresh();
        $this->assertSame(UssdSubscription::STATUS_PAID, $fresh->status, 'A successful USSD payment must never be turned into a failure.');
        $this->assertNull($fresh->reconciliation_note);
    }

    public function test_ambiguous_paid_but_undelivered_result_checker_order_is_skipped(): void
    {
        $order = $this->legacyResultChecker('CLOSE-RC-AMBIGUOUS', now()->subMonths(6), 'pending_stock');

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertSame('pending_stock', $fresh->status, 'A paid-but-undelivered result checker order must never be modified.');
        $this->assertNull($fresh->reconciliation_note);
    }

    // -----------------------------------------------------------------
    // 17. current/new customer payments continue operating normally
    // -----------------------------------------------------------------

    public function test_current_customer_payments_continue_operating_normally(): void
    {
        $this->makePaystackConfig();

        $legacyOrder = $this->legacyOrder('CLOSE-LEGACY-COEXIST', now()->subMonths(6));
        $currentOrder = $this->legacyOrder('CLOSE-CURRENT-COEXIST', now()->subMinutes(10));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertSame('failed', $legacyOrder->fresh()->payment_status);
        $this->assertSame('unpaid', $currentOrder->fresh()->payment_status, 'Legacy closure must not touch a current pending order.');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('paid', $currentOrder->fresh()->payment_status, 'A current customer payment must reconcile normally.');
        $this->assertSame('failed', $legacyOrder->fresh()->payment_status, 'The already-closed legacy order must remain untouched.');
    }

    // -----------------------------------------------------------------
    // --type restricts to one payable
    // -----------------------------------------------------------------

    public function test_type_option_restricts_closure_to_one_payable(): void
    {
        $order = $this->legacyOrder('CLOSE-TYPE-ORDER', now()->subMonths(6));
        $afa = $this->legacyAfa('CLOSE-TYPE-AFA', now()->subMonths(6));

        $this->artisan('payments:close-legacy', [
            '--before' => now()->subMonths(1)->toDateString(),
            '--execute' => true,
            '--type' => 'order',
        ])->assertExitCode(0);

        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $afa->fresh()->payment_status, '--type=order must not reach AFA records.');
    }
}
