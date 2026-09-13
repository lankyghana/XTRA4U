<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\User;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\Admin\PaymentHealthService;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 6 — Payment Operations & Monitoring.
 *
 * This is read-only-first: PaymentHealthService and PaymentHealthController
 * never verify, complete, reconcile, or mutate payments themselves — they
 * only read the columns PaymentReconciliationService (untouched by this
 * phase) already writes. These tests therefore assert on *visibility*
 * (does the right thing show up, correctly bucketed, with nothing secret
 * leaked) and on the one mutating action's tight blast radius.
 */
class AdminPaymentHealthTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function actingCustomerLikeUser(): User
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user);

        return $user;
    }

    private function makePaystackConfig(bool $default = true): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_super_secret_should_never_leak',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    private function order(array $overrides = []): Order
    {
        $vendor = Vendor::factory()->create();

        // A handful of override keys (reconciliation_* bookkeeping,
        // external_fulfillment_status, created_at) are intentionally not
        // mass-assignable on Order — set those directly via a raw update
        // after creation instead of forcing them through create().
        $rawOnly = ['created_at', 'reconciliation_attempts', 'reconciliation_note', 'next_reconciliation_at', 'external_fulfillment_status'];
        $raw = array_intersect_key($overrides, array_flip($rawOnly));
        $fillableOverrides = array_diff_key($overrides, $raw);

        $order = Order::create(array_merge([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 50.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_reference' => 'ORD-'.uniqid(),
            'payment_gateway' => 'paystack',
        ], $fillableOverrides));

        if ($raw) {
            DB::table('orders')->where('id', $order->id)->update($raw);
        }

        return $order->fresh();
    }

    private function walletTopup(array $overrides = []): WalletTopup
    {
        $vendor = Vendor::factory()->create();

        $topup = WalletTopup::create(array_merge([
            'reference' => 'WT-'.uniqid(),
            'vendor_id' => $vendor->id,
            'amount' => 20.00,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
        ], $overrides));

        if (isset($overrides['created_at'])) {
            DB::table('wallet_topups')->where('id', $topup->id)->update(['created_at' => $overrides['created_at']]);
        }

        return $topup->fresh();
    }

    private function ussdSubscription(array $overrides = []): UssdSubscription
    {
        $vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $subscription = UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => 'USSD-'.uniqid(),
            'payment_gateway' => 'paystack',
        ], $overrides));

        if (isset($overrides['created_at'])) {
            DB::table('ussd_subscriptions')->where('id', $subscription->id)->update(['created_at' => $overrides['created_at']]);
        }

        return $subscription->fresh();
    }

    // -----------------------------------------------------------------
    // 1. Authorization
    // -----------------------------------------------------------------

    public function test_non_admin_cannot_access_payment_health_dashboard(): void
    {
        $this->actingCustomerLikeUser();

        $response = $this->get(route('admin.payment-health.index'));

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $response = $this->get(route('admin.payment-health.index'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_cannot_trigger_recheck(): void
    {
        $order = $this->order();
        $this->actingCustomerLikeUser();

        $response = $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => $order->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_view_dashboard(): void
    {
        $this->actingAdmin();

        $response = $this->get(route('admin.payment-health.index'));

        $response->assertOk();
        $response->assertViewIs('admin.payment_health.index');
    }

    // -----------------------------------------------------------------
    // 2. Bucket correctness
    // -----------------------------------------------------------------

    public function test_pending_age_buckets_are_correct(): void
    {
        $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subMinutes(5)]);
        $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subHours(2)]);
        $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subHours(30)]);

        $summary = app(PaymentHealthService::class)->summary();
        $bucket = $summary['per_type']['order'];

        $this->assertSame(1, $bucket['pending_under_30m']);
        $this->assertSame(1, $bucket['pending_30m_to_24h']);
        $this->assertSame(1, $bucket['pending_over_24h']);
    }

    public function test_all_five_payable_types_appear_in_summary(): void
    {
        $summary = app(PaymentHealthService::class)->summary();

        foreach (PaymentHealthService::TYPES as $type) {
            $this->assertArrayHasKey($type, $summary['per_type']);
        }
    }

    public function test_manual_review_records_appear_in_queue(): void
    {
        $order = $this->order([
            'payment_status' => 'unpaid',
            'reconciliation_attempts' => 5,
            'reconciliation_note' => 'manual_review: max automatic reconciliation window exceeded',
            'next_reconciliation_at' => now()->addYears(10),
        ]);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index', ['state' => 'manual_review']));

        $response->assertOk();
        $response->assertSee($order->payment_reference);
    }

    public function test_missing_gateway_wallet_topup_appears_as_manual_review_original_gateway_unknown(): void
    {
        $topup = $this->walletTopup([
            'payment_gateway' => null,
            'status' => 'initiated',
        ]);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index', ['state' => 'missing_gateway']));

        $response->assertOk();
        $response->assertSee($topup->reference);
        $response->assertSee('Manual Review / Original Gateway Unknown');

        $summary = app(PaymentHealthService::class)->summary();
        $this->assertGreaterThanOrEqual(1, $summary['per_type']['wallet_topup']['missing_gateway']);
    }

    public function test_missing_gateway_queue_row_never_offers_a_recheck_button(): void
    {
        $this->walletTopup(['payment_gateway' => null]);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index', ['state' => 'missing_gateway']));

        $response->assertOk();
        $response->assertDontSee('Recheck Payment');
    }

    public function test_integrity_mismatch_is_highlighted_in_summary(): void
    {
        $this->order([
            'payment_status' => 'unpaid',
            'reconciliation_attempts' => 1,
            'reconciliation_note' => 'manual_review: integrity_mismatch',
            'next_reconciliation_at' => now()->addYears(10),
        ]);

        $summary = app(PaymentHealthService::class)->summary();

        $this->assertSame(1, $summary['per_type']['order']['integrity_mismatch']);
        $this->assertSame(1, $summary['totals']['integrity_mismatch']);
    }

    // -----------------------------------------------------------------
    // 11. USSD status consistency (Phase 5 compatibility)
    // -----------------------------------------------------------------

    public function test_ussd_status_payment_failed_counts_as_confirmed_failed(): void
    {
        $this->ussdSubscription(['status' => UssdSubscription::STATUS_PAYMENT_FAILED]);

        $summary = app(PaymentHealthService::class)->summary();

        $this->assertSame(1, $summary['per_type']['ussd']['confirmed_failed']);
    }

    public function test_legacy_pending_payment_ussd_row_with_failed_event_counts_as_confirmed_failed(): void
    {
        // Legacy row: created before STATUS_PAYMENT_FAILED existed, so it is
        // still stuck at pending_payment, but a PAYMENT_FAILED event was
        // logged against it (mirrors UssdSubscriptionPurchaseService::
        // isTerminallyFailed()'s own compatibility check).
        $subscription = $this->ussdSubscription(['status' => UssdSubscription::STATUS_PENDING_PAYMENT]);
        UssdSubscriptionEvent::create([
            'ussd_subscription_id' => $subscription->id,
            'vendor_id' => $subscription->vendor_id,
            'event' => UssdSubscriptionEvent::PAYMENT_FAILED,
        ]);

        $summary = app(PaymentHealthService::class)->summary();

        $this->assertSame(1, $summary['per_type']['ussd']['confirmed_failed']);
    }

    public function test_plain_pending_payment_ussd_row_without_failed_event_is_not_confirmed_failed(): void
    {
        $this->ussdSubscription(['status' => UssdSubscription::STATUS_PENDING_PAYMENT]);

        $summary = app(PaymentHealthService::class)->summary();

        $this->assertSame(0, $summary['per_type']['ussd']['confirmed_failed']);
    }

    // -----------------------------------------------------------------
    // Filters & pagination
    // -----------------------------------------------------------------

    public function test_type_filter_restricts_queue_to_one_payable_type(): void
    {
        $order = $this->order(['payment_status' => 'unpaid']);
        $topup = $this->walletTopup(['status' => 'initiated']);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index', ['type' => 'order']));

        $response->assertOk();
        $response->assertSee($order->payment_reference);
        $response->assertDontSee($topup->reference);
    }

    public function test_gateway_filter_restricts_queue(): void
    {
        $paystackOrder = $this->order(['payment_status' => 'unpaid', 'payment_gateway' => 'paystack']);
        $otherOrder = $this->order(['payment_status' => 'unpaid', 'payment_gateway' => 'hubtel']);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index', ['gateway' => 'hubtel']));

        $response->assertOk();
        $response->assertSee($otherOrder->payment_reference);
        $response->assertDontSee($paystackOrder->payment_reference);
    }

    public function test_reference_filter_matches_partial_reference(): void
    {
        $order = $this->order(['payment_status' => 'unpaid', 'payment_reference' => 'UNIQUE-SEARCH-TOKEN']);
        $this->order(['payment_status' => 'unpaid', 'payment_reference' => 'OTHER-REF']);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index', ['reference' => 'UNIQUE-SEARCH']));

        $response->assertOk();
        $response->assertSee($order->payment_reference);
        $response->assertDontSee('OTHER-REF');
    }

    public function test_pagination_limits_rows_per_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->order(['payment_status' => 'unpaid', 'payment_reference' => 'PAGE-'.$i]);
        }

        $queue = app(PaymentHealthService::class)->manualReviewQueue([], 10);

        $this->assertCount(10, $queue->items());
        $this->assertSame(30, $queue->total());
        $this->assertTrue($queue->hasPages());
    }

    public function test_queue_does_not_load_every_record_eagerly(): void
    {
        // A resolved order with no reconciliation history at all must never
        // appear — the default "needs attention" filter excludes the
        // overwhelming majority of normal, successfully-paid traffic.
        $normal = $this->order(['payment_status' => 'paid']);
        $pending = $this->order(['payment_status' => 'unpaid']);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index'));

        $response->assertOk();
        $response->assertSee($pending->payment_reference);
        $response->assertDontSee($normal->payment_reference);
    }

    // -----------------------------------------------------------------
    // 5/6. Manual recheck — tight blast radius
    // -----------------------------------------------------------------

    public function test_recheck_uses_the_payables_own_stored_gateway_and_reference(): void
    {
        $this->makePaystackConfig();
        $order = $this->order([
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'RECHECK-OWN-REF',
            'created_at' => now()->subMinutes(10),
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 5000],
            ], 200),
        ]);

        $this->actingAdmin();
        $response = $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => $order->id,
        ]);

        $response->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'RECHECK-OWN-REF'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_recheck_cannot_accept_an_arbitrary_gateway(): void
    {
        $this->makePaystackConfig();
        $order = $this->order([
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'created_at' => now()->subMinutes(10),
        ]);

        Http::fake([
            'https://api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200),
            'https://api.hubtel.com/*' => Http::response('must never be called', 500),
        ]);

        $this->actingAdmin();
        // An attacker-controlled "gateway" field is not even accepted by
        // validation — only payable_type/payable_id are.
        $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => $order->id,
            'gateway' => 'hubtel',
        ]);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'hubtel'));
    }

    public function test_recheck_never_initializes_a_new_charge(): void
    {
        $this->makePaystackConfig();
        $order = $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subMinutes(10)]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $this->actingAdmin();
        $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => $order->id,
        ]);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/transaction/initialize'));
        $this->assertSame(1, Order::count());
    }

    public function test_recheck_cannot_force_success_for_a_still_pending_gateway_response(): void
    {
        $this->makePaystackConfig();
        $order = $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subMinutes(10)]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $this->actingAdmin();
        $response = $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => $order->id,
        ]);

        // Stays exactly what the gateway actually said — UNKNOWN/PENDING is
        // never force-upgraded to SUCCESS by this action.
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $response->assertSessionHas('success');
        $this->assertStringContainsString('PENDING', session('success'));
    }

    public function test_recheck_never_exposes_raw_provider_response_to_the_browser(): void
    {
        $this->makePaystackConfig();
        $order = $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subMinutes(10)]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => false,
                'message' => 'super-secret-internal-provider-diagnostic-string',
            ], 200),
        ]);

        $this->actingAdmin();
        $response = $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => $order->id,
        ]);

        $response->assertRedirect();
        $followUp = $this->get(route('admin.payment-health.index'));
        $followUp->assertDontSee('super-secret-internal-provider-diagnostic-string');
    }

    public function test_recheck_rejects_unknown_payable_type(): void
    {
        $this->actingAdmin();

        $response = $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'not_a_real_type',
            'payable_id' => 1,
        ]);

        $response->assertSessionHasErrors('payable_type');
    }

    public function test_recheck_requires_csrf_token(): void
    {
        $order = $this->order(['payment_status' => 'unpaid']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        // withoutMiddleware would bypass CSRF entirely, so instead assert
        // the route sits behind the global web middleware group which
        // includes VerifyCsrfToken by inspecting the route's middleware.
        $middleware = collect(\Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.payment-health.recheck')->gatherMiddleware());

        $this->assertTrue($middleware->contains('web'), 'The route must run through the web middleware group (CSRF included).');
    }

    // -----------------------------------------------------------------
    // 8. Scheduler heartbeat
    // -----------------------------------------------------------------

    public function test_scheduler_heartbeat_updates_after_a_reconcile_run(): void
    {
        $this->makePaystackConfig();
        $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subMinutes(10)]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 5000],
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $health = app(PaymentHealthService::class)->schedulerHealth();

        $this->assertTrue($health['known']);
        $this->assertSame('ok', $health['status']);
        $this->assertArrayHasKey('totals', $health);
    }

    public function test_stale_heartbeat_is_reported_critical(): void
    {
        app(PaymentHealthService::class)->recordReconciliationHeartbeat([
            'status' => 'ok',
            'started_at' => now()->subHour()->toIso8601String(),
            'duration_seconds' => 1,
            'records_examined' => 0,
            'totals' => [],
        ]);
        Cache::put(PaymentHealthService::HEARTBEAT_CACHE_KEY, array_merge(
            Cache::get(PaymentHealthService::HEARTBEAT_CACHE_KEY),
            ['finished_at' => now()->subMinutes(45)->toIso8601String()]
        ));

        $health = app(PaymentHealthService::class)->schedulerHealth();

        $this->assertSame('critical', $health['status']);
    }

    public function test_no_heartbeat_ever_recorded_is_reported_critical(): void
    {
        $health = app(PaymentHealthService::class)->schedulerHealth();

        $this->assertFalse($health['known']);
        $this->assertSame('critical', $health['status']);
    }

    public function test_heartbeat_is_still_recorded_when_the_reconcile_run_crashes(): void
    {
        $this->partialMock(PaymentReconciliationService::class, function ($mock) {
            $mock->shouldReceive('reconcile')->andThrow(new \RuntimeException('boom'));
        });
        $this->makePaystackConfig();
        $this->order(['payment_status' => 'unpaid', 'created_at' => now()->subMinutes(10)]);

        // processBatch() catches per-payable exceptions itself, so a single
        // throwing payable does not crash handle() — heartbeat still records
        // 'ok' with an exception tallied. This asserts the heartbeat exists
        // either way, matching the "finally" guarantee.
        $this->artisan('payments:reconcile')->run();

        $health = app(PaymentHealthService::class)->schedulerHealth();
        $this->assertTrue($health['known']);
    }

    // -----------------------------------------------------------------
    // 9. Queue / fulfillment visibility
    // -----------------------------------------------------------------

    public function test_failed_jobs_are_visible_in_queue_health(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessExternalFulfillment']),
            'exception' => "Some exception message\nwith a stack trace",
            'failed_at' => now(),
        ]);

        $health = app(PaymentHealthService::class)->queueHealth();

        $this->assertSame(1, $health['failed_jobs_total']);
        $this->assertCount(1, $health['failed_jobs_recent']);
        $this->assertSame('Some exception message', $health['failed_jobs_recent'][0]['exception_summary']);
    }

    public function test_orders_awaiting_external_fulfillment_are_counted(): void
    {
        $this->order(['payment_status' => 'paid', 'external_fulfillment_status' => 'pending']);
        $this->order(['payment_status' => 'unpaid']);

        $health = app(PaymentHealthService::class)->queueHealth();

        $this->assertSame(1, $health['orders_awaiting_fulfillment']);
    }

    // -----------------------------------------------------------------
    // 10. Alerts
    // -----------------------------------------------------------------

    public function test_integrity_mismatch_triggers_a_critical_alert(): void
    {
        $this->order([
            'payment_status' => 'unpaid',
            'reconciliation_attempts' => 1,
            'reconciliation_note' => 'manual_review: integrity_mismatch',
            'next_reconciliation_at' => now()->addYears(10),
        ]);

        $service = app(PaymentHealthService::class);
        $summary = $service->summary();
        $alerts = $service->alerts($summary, $service->schedulerHealth(), $service->queueHealth());

        $this->assertTrue(collect($alerts)->contains(fn ($a) => $a['level'] === 'critical' && str_contains($a['message'], 'integrity mismatch')));
    }

    public function test_no_alerts_fire_for_a_clean_healthy_state(): void
    {
        app(PaymentHealthService::class)->recordReconciliationHeartbeat([
            'status' => 'ok',
            'started_at' => now()->toIso8601String(),
            'duration_seconds' => 1,
            'records_examined' => 0,
            'totals' => [],
        ]);

        $service = app(PaymentHealthService::class);
        $summary = $service->summary();
        $alerts = $service->alerts($summary, $service->schedulerHealth(), $service->queueHealth());

        $this->assertSame([], $alerts);
    }

    // -----------------------------------------------------------------
    // 3/13. No secrets ever rendered
    // -----------------------------------------------------------------

    public function test_no_gateway_secrets_appear_anywhere_in_the_rendered_page(): void
    {
        $this->makePaystackConfig();
        $this->order(['payment_status' => 'unpaid']);
        $this->walletTopup(['payment_gateway' => null]);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index'));

        $response->assertOk();
        $response->assertDontSee('sk_test_super_secret_should_never_leak');
        $response->assertDontSee('secret_key');
        $response->assertDontSee('config_data');
    }

    public function test_no_mark_paid_or_force_success_controls_exist_on_the_page(): void
    {
        $this->order(['payment_status' => 'unpaid']);

        $this->actingAdmin();
        $response = $this->get(route('admin.payment-health.index'));

        $response->assertOk();
        foreach (['Mark Paid', 'Force Success', 'Override Gateway', 'Skip Verification', 'Ignore Amount Mismatch'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }
    }

    // -----------------------------------------------------------------
    // 13. IDOR — cross-vendor / cross-type leakage
    // -----------------------------------------------------------------

    public function test_recheck_rejects_a_payable_id_that_does_not_exist(): void
    {
        $this->actingAdmin();

        $response = $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'order',
            'payable_id' => 999999,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_recheck_id_is_scoped_to_the_declared_payable_type_not_cross_type(): void
    {
        // An order and a wallet topup happen to share the same numeric id
        // space; posting payable_type=wallet_topup with an order's id must
        // resolve (or fail) against WalletTopup only, never touch the order.
        $order = $this->order(['payment_status' => 'unpaid']);

        $this->actingAdmin();
        $this->post(route('admin.payment-health.recheck'), [
            'payable_type' => 'wallet_topup',
            'payable_id' => $order->id,
        ]);

        $this->assertSame('unpaid', $order->fresh()->payment_status, 'The order must never be touched by a wallet_topup-typed recheck.');
    }
}
