<?php

namespace Tests\Feature\UtilityBills;

use App\Jobs\SubmitUtilityBillPayment;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Models\WalletLedger;
use App\Services\PaymentReconciliationService;
use App\Services\PaymentService;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\UtilityBillAvailability;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSettings;
use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-production hardening: provider attempts, commission across attempts,
 * default-off safety, platform-order (vendor_id NULL) regressions, the
 * PaymentService utility branch, and the exact provider request contract.
 */
class UtilityBillHardeningTest extends UtilityBillTestCase
{
    private function svc(): UtilityBillFulfillmentService
    {
        return app(UtilityBillFulfillmentService::class);
    }

    private function payCount(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/utilities/pay'))->count();
    }

    /** A paid order whose provider attempt 1 ended as `refunded`. */
    private function refundedOrder(?Vendor $vendor = null, string $amount = '100.00'): UtilityBillOrder
    {
        $vendor ??= Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 0]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-ATT1'))]);
        $u = $this->makeOrder(['vendor' => $vendor, 'paid' => true, 'amount' => $amount]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded', 'UTIL-DSTV-ATT1'))]);
        $this->svc()->syncStatus($u->id);

        return $u->refresh();
    }

    // =====================================================================
    // New provider attempt (N+1) rule
    // =====================================================================

    public function test_a_new_reference_needs_provider_confirmed_refund_a_reason_and_confirmation(): void
    {
        $u = $this->refundedOrder();
        $oldRef = $u->provider_request_reference;

        // No reason.
        $this->assertFalse($this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true)['ok']);
        $this->assertFalse($this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true, reason: 'no')['ok']);
        // Plain retry on a refunded order never creates a new reference.
        $this->assertFalse($this->svc()->adminRetry($u->id, ['id' => 1])['ok']);

        $this->assertSame($oldRef, $u->fresh()->provider_request_reference);
        $this->assertSame(1, $u->fresh()->provider_attempt);
        $this->assertSame(0, $this->payCount());      // nothing further was paid after the refund
    }

    public function test_failed_status_never_permits_a_new_reference(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-F1'))]);
        $u = $this->makeOrder(['paid' => true]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('failed', 'UTIL-DSTV-F1'))]);
        $this->svc()->syncStatus($u->id);
        $this->assertSame(FulfillmentStatus::FAILED, $u->fresh()->fulfillment_status);

        $res = $this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true, reason: 'Customer is waiting for it');

        $this->assertFalse($res['ok']);
        $this->assertSame(1, $u->fresh()->provider_attempt);
        $this->assertSame(0, $this->payCount());
    }

    public function test_the_provider_is_asked_live_and_a_non_refunded_answer_blocks_the_new_attempt(): void
    {
        $u = $this->refundedOrder();

        // The provider now says the SAME order actually completed.
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-DSTV-ATT1', 1.3))]);
        $res = $this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true, reason: 'Provider refunded; customer still needs it');

        $this->assertFalse($res['ok']);
        $u->refresh();
        $this->assertSame(1, $u->provider_attempt);
        $this->assertSame(0, $this->payCount());
    }

    public function test_an_unreachable_provider_never_justifies_a_new_reference(): void
    {
        $u = $this->refundedOrder();

        foreach ([
            fn () => throw new ConnectionException('timeout'),
            fn () => Http::response('<html>bad gateway</html>', 502),
            fn () => Http::response(['success' => false], 429),
            fn () => Http::response(['success' => true, 'data' => 'garbage'], 200),
        ] as $outcome) {
            RateLimiter::clear('utility-bills:provider:status');
            $this->fake([self::BASE.'/utilities/orders/*' => $outcome]);

            $res = $this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true, reason: 'Provider refunded; customer still needs it');

            $this->assertFalse($res['ok']);
            $this->assertSame(1, $u->fresh()->provider_attempt);
        }
        $this->assertSame(0, $this->payCount());
    }

    public function test_uncertain_outcomes_always_retry_with_the_same_reference(): void
    {
        $cases = [
            'timeout' => fn () => throw new ConnectionException('timeout'),
            '5xx' => fn () => Http::response(['success' => false], 500),
            '502' => fn () => Http::response('bad gateway', 502),
            '429' => fn () => Http::response(['success' => false], 429),
            '409 duplicate window' => fn () => Http::response(['success' => false, 'message' => 'duplicate'], 409),
            'malformed' => fn () => Http::response(['success' => true, 'data' => ['no' => 'reference']], 200),
        ];

        foreach ($cases as $label => $outcome) {
            RateLimiter::clear('utility-bills:provider:pay');
            $this->fake([self::BASE.'/utilities/pay' => $outcome]);
            $u = $this->makeOrder(['paid' => true]);
            $u->refresh();

            $ref = $u->provider_request_reference;
            $this->assertNotNull($ref, $label);
            $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status, $label);
            $this->assertSame(1, $u->provider_attempt, $label);
            $this->assertNull($u->provider_order_reference, $label);

            // Retry (as the scheduler/admin would) - identical reference every time.
            RateLimiter::clear('utility-bills:provider:pay');
            $this->fake([self::BASE.'/utilities/pay' => $outcome]);
            $this->svc()->submit($u->id, force: true);
            $this->assertSame($ref, $u->fresh()->provider_request_reference, $label);
            $this->assertSame(1, $u->fresh()->provider_attempt, $label);
        }
    }

    public function test_a_stale_local_claim_and_queue_failure_reuse_the_same_reference(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('x')]);
        $u = $this->makeOrder(['paid' => true]);
        $ref = $u->refresh()->provider_request_reference;
        // A worker died mid-flight: claimed long ago, and the order was due when it was claimed.
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::SUBMITTING, 'claim_token' => 'dead', 'claimed_at' => now()->subHour(), 'next_submit_at' => null])->save();

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-REC'))]);
        app(UtilityBillSweeper::class)->run();

        $u->refresh();
        $this->assertSame($ref, $u->provider_request_reference);
        $this->assertSame(1, $u->provider_attempt);
        $this->assertSame('UTIL-DSTV-REC', $u->provider_order_reference);
    }

    public function test_409_duplicate_window_is_retried_later_not_failed_or_re_referenced(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'duplicate order'], 409)]);
        $u = $this->makeOrder(['paid' => true])->refresh();

        $this->assertSame(FulfillmentStatus::QUEUED, $u->fulfillment_status);
        $this->assertSame('duplicate_window', $u->last_error_code);
        $this->assertTrue($u->next_submit_at->isFuture());
        $this->assertSame('paid', Order::find($u->order_id)->payment_status);
    }

    // =====================================================================
    // Attempt history
    // =====================================================================

    public function test_starting_attempt_two_preserves_attempt_one_as_a_structured_record(): void
    {
        $u = $this->refundedOrder();
        $firstRequest = $u->provider_request_reference;

        $this->fake([
            self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded', 'UTIL-DSTV-ATT1')),
            self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-ATT2')),
        ]);
        $res = $this->svc()->adminRetry($u->id, ['id' => 42, 'email' => 'a@x.test'], newAttempt: true, reason: 'Provider refunded; customer still needs it');
        $this->assertTrue($res['ok']);

        $u->refresh();
        $this->assertSame(2, $u->provider_attempt);
        $this->assertNotSame($firstRequest, $u->provider_request_reference);
        $this->assertSame('UTIL-DSTV-ATT2', $u->provider_order_reference);

        $closed = UtilityBillEvent::where('utility_bill_order_id', $u->id)->where('kind', UtilityBillEvent::KIND_ATTEMPT_CLOSED)->sole();
        $this->assertSame(1, $closed->meta['attempt']);
        $this->assertSame($firstRequest, $closed->meta['request_reference']);
        $this->assertSame('UTIL-DSTV-ATT1', $closed->meta['provider_order_reference']);
        $this->assertSame('refunded', $closed->meta['provider_status']);
        $this->assertSame('admin:42', $closed->meta['started_by']);
        $this->assertSame('Provider refunded; customer still needs it', $closed->meta['reason']);
        $this->assertSame(2, $closed->meta['new_attempt']);
        $this->assertNotEmpty($closed->meta['submitted_at']);
        $this->assertNotEmpty($closed->meta['closed_at']);

        // Admin page shows both attempts.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.utility-bill-sales.show', $u))->assertOk()
            ->assertSee('Provider attempts')->assertSee($firstRequest)->assertSee('UTIL-DSTV-ATT1')->assertSee('UTIL-DSTV-ATT2');
    }

    public function test_the_admin_ui_distinguishes_retry_from_a_new_attempt(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        // Retry (same reference) is offered for an attention order, a new attempt is not.
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400)]);
        $attention = $this->makeOrder(['paid' => true])->refresh();
        $page = $this->get(route('admin.utility-bill-sales.show', $attention))->assertOk();
        $page->assertSee('Retry the existing attempt')->assertSee('SAME provider reference')->assertSee('cannot')->assertDontSee('Start a NEW provider attempt');

        // A refunded order offers the new attempt with explicit wording, and no plain retry.
        $refunded = $this->refundedOrder();
        $this->get(route('admin.utility-bill-sales.show', $refunded))->assertOk()
            ->assertSee('Start a NEW provider attempt')->assertSee('NEW provider reference')->assertSee('not</strong> a customer refund', false)
            ->assertDontSee('Retry the existing attempt');
    }

    // =====================================================================
    // Commission across attempts
    // =====================================================================

    public function test_attempt_one_refunded_attempt_two_completed_pays_the_vendor_exactly_once(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 0]);
        $u = $this->refundedOrder($vendor, '200.00');
        $this->assertSame('0.00', (string) $vendor->fresh()->wallet_balance);        // refunded attempt earns nothing
        $this->assertSame(0, WalletLedger::count());

        $this->fake([
            self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded', 'UTIL-DSTV-ATT1')),
            self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-ATT2')),
        ]);
        $this->assertTrue($this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: true, reason: 'Provider refunded; customer still needs it')['ok']);

        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-DSTV-ATT2', 2.6))]);
        $this->svc()->syncStatus($u->id);
        $this->svc()->afterStatusChange($u->id);
        $this->svc()->afterStatusChange($u->id);

        $u->refresh();
        $this->assertSame(FulfillmentStatus::COMPLETED, $u->fulfillment_status);
        $this->assertSame('credited', $u->commission_status);
        $this->assertSame('2.00', (string) $vendor->fresh()->wallet_balance);      // 1% of 200, once
        $this->assertSame(1, WalletLedger::where('source', 'utility_bill_commission')->count());
    }

    public function test_once_an_attempt_completed_no_further_attempt_is_possible(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-DONE'))]);
        $u = $this->makeOrder(['paid' => true]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-DSTV-DONE'))]);
        $this->svc()->syncStatus($u->id);

        foreach ([false, true] as $newAttempt) {
            $res = $this->svc()->adminRetry($u->id, ['id' => 1], newAttempt: $newAttempt, reason: 'Provider refunded; customer still needs it');
            $this->assertFalse($res['ok']);
        }
        $this->assertSame(0, $this->payCount());
        $this->assertSame(1, $u->fresh()->provider_attempt);
    }

    public function test_a_late_contradicting_status_cannot_open_a_second_attempt_or_second_commission(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 0]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-C1'))]);
        $u = $this->makeOrder(['vendor' => $vendor, 'paid' => true]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-DSTV-C1'))]);
        $this->svc()->syncStatus($u->id);

        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('refunded', 'UTIL-DSTV-C1'))]);
        $this->svc()->applyProviderStatus($u->id, new \App\Services\UtilityBills\Data\StatusResult('UTIL-DSTV-C1', 'refunded', 'paid', null, null, null, null));

        $this->assertSame(FulfillmentStatus::COMPLETED, $u->fresh()->fulfillment_status);
        $this->assertSame(1, WalletLedger::count());
    }

    // =====================================================================
    // Default-off safety
    // =====================================================================

    public function test_a_fresh_system_sells_nothing(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        $this->assertFalse(UtilityBillSettings::enabled());
        $this->assertSame(0, UtilityBillerConfig::count());          // migrations seed no biller rows
        $this->assertSame(0, Setting::where('group', 'utility_bills')->count());
        $this->assertTrue(app(UtilityBillAvailability::class)->sellable()->isEmpty());
        $this->get('/services/utility-bills')->assertStatus(503);
        Http::assertNothingSent();                                    // not even the catalog is fetched while off
    }

    public function test_billers_are_off_and_commission_is_zero_until_an_admin_sets_them(): void
    {
        UtilityBillSettings::save(true, null);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        // Service on, but no biller enabled by admin: still nothing for sale.
        $this->assertTrue(app(UtilityBillAvailability::class)->sellable()->isEmpty());

        // A freshly created config row is disabled with zero commission.
        $config = UtilityBillerConfig::create(['biller_key' => 'ecg']);
        $config->refresh();
        $this->assertFalse($config->is_enabled);
        $this->assertSame('percentage', $config->commission_type);
        $this->assertSame('0.0000', (string) $config->commission_value);
        $this->assertTrue(app(UtilityBillAvailability::class)->sellable()->isEmpty());
    }

    public function test_only_an_explicit_stored_one_opens_the_service(): void
    {
        foreach (['0', '', '2', 'true', 'on', 'yes', ' 1', '1 ', 'enabled'] as $value) {
            Setting::set(UtilityBillSettings::KEY_ENABLED, $value, UtilityBillSettings::GROUP);
            $this->assertFalse(UtilityBillSettings::enabled(), var_export($value, true));
        }
        Setting::set(UtilityBillSettings::KEY_ENABLED, '1', UtilityBillSettings::GROUP);
        $this->assertTrue(UtilityBillSettings::enabled());
    }

    public function test_an_unreadable_settings_store_means_off_never_on(): void
    {
        Setting::set(UtilityBillSettings::KEY_ENABLED, '1', UtilityBillSettings::GROUP);
        $this->assertTrue(UtilityBillSettings::enabled());

        // Settings are read via Cache::remember(); make the store explode.
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('cache store down'));
        $this->assertFalse(UtilityBillSettings::enabled());
    }

    public function test_missing_or_wrong_type_api_key_means_unavailable_even_when_everything_is_enabled(): void
    {
        $this->openService(['ecg']);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        foreach ([null, '', '   ', 'kf_live_normaldatakey', 'kf_sms_live_x', 'KF_CS_LIVE_X', 'cs_live_x'] as $key) {
            config(['services.kingflexy_utilities.api_key' => $key]);
            $this->assertFalse(app(KingFlexyUtilityProvider::class)->isConfigured(), var_export($key, true));
            $this->assertTrue(app(UtilityBillAvailability::class)->sellable()->isEmpty());
            $this->get('/services/utility-bills')->assertStatus(503);
        }
        Http::assertNothingSent();
    }

    public function test_the_utility_migrations_do_not_enable_or_seed_anything(): void
    {
        foreach (glob(database_path('migrations/2026_10_07_*.php')) as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('->insert(', $source, basename($file));
            $this->assertStringNotContainsString('DB::table(', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/(?<!Schema)::create\(/', $source, basename($file));
            $this->assertStringNotContainsString('Setting::', $source, basename($file));
            $this->assertStringNotContainsString('is_enabled\')->default(true', $source, basename($file));
        }
    }

    // =====================================================================
    // orders.vendor_id NULL (platform-owned Utility Bill orders)
    // =====================================================================

    public function test_vendorless_orders_cannot_be_created_through_the_existing_checkout_paths(): void
    {
        // The DB now allows NULL, so the application-level validation must still hold.
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $before = Order::count();

        $this->postJson(route('checkout.process'), ['service_id' => '1', 'package_id' => '1', 'amount' => 10, 'recipient_phone' => '0551234567'])
            ->assertStatus(422)->assertJsonValidationErrors('vendor_id');
        $this->postJson(route('checkout.process'), ['vendor_id' => null, 'service_id' => '1', 'package_id' => '1', 'amount' => 10, 'recipient_phone' => '0551234567'])
            ->assertStatus(422);
        $this->postJson(route('checkout.process'), ['vendor_id' => 999999, 'service_id' => '1', 'package_id' => '1', 'amount' => 10, 'recipient_phone' => '0551234567'])
            ->assertStatus(422);

        $this->assertSame($before, Order::count());
        $this->assertNotNull($vendor->id);
    }

    public function test_platform_orders_never_leak_into_vendor_views_or_wallets(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 5]);
        $platform = $this->makeOrder(['vendor' => null, 'paid' => true]);

        Auth::guard('vendor')->setUser($vendor);
        Auth::shouldUse('web');
        $this->get(route('vendor.orders.index'))->assertOk()->assertDontSee($platform->public_ref)->assertDontSee('Utility Bill:');
        $this->get(route('vendor.dashboard'))->assertOk();

        $this->assertSame('5.00', (string) $vendor->fresh()->wallet_balance);
        $this->assertSame(0, \App\Models\Transaction::count());
    }

    public function test_admin_and_public_surfaces_tolerate_a_vendorless_order(): void
    {
        $platform = $this->makeOrder(['vendor' => null, 'paid' => true]);
        $order = Order::find($platform->order_id);
        $this->assertNull($order->vendor_id);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            route('admin.dashboard'),
            route('admin.orders.index'),
            route('admin.orders.show', $order),
            route('admin.transactions.index'),
            route('admin.reports.index'),
            route('admin.payment-health.index'),
            route('admin.utility-bill-sales.index'),
            route('admin.utility-bill-sales.show', $platform),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_platform_orders_are_not_exposed_through_public_id_or_phone_endpoints(): void
    {
        $platform = $this->makeOrder(['vendor' => null, 'paid' => true, 'phone' => '0551617309']);
        $order = Order::find($platform->order_id);

        // Sequential-id pages: never served, never redirect to the token.
        $this->get(route('checkout.success', ['order' => $order->id]))->assertNotFound();
        $this->get(route('checkout.receipt', ['order' => $order->id]))->assertNotFound();

        // Public phone lookup and id polling do not list it.
        $this->postJson(route('order.status.check'), ['phone' => '0551617309'])->assertJson(['success' => false]);
        $this->postJson(route('order.status.poll'), ['order_ids' => [$order->id]])->assertJson(['orders' => []]);
        $this->assertStringNotContainsString($platform->access_token, $this->get(route('checkout.success', ['order' => $order->id]))->getContent());
    }

    public function test_ordinary_vendor_orders_are_still_listed_publicly(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        Order::create([
            'recipient_phone_number' => '0241112222', 'mobile_money_number' => '0241112222', 'service_purchased' => 'MTN 1GB',
            'amount_paid' => 5, 'vendor_id' => $vendor->id, 'status' => 'Processing', 'payment_status' => 'paid',
        ]);

        $this->postJson(route('order.status.check'), ['phone' => '0241112222'])->assertJson(['success' => true]);
    }

    public function test_the_nullable_migration_preserves_the_foreign_key_and_indexes(): void
    {
        $fks = collect(Schema::getForeignKeys('orders'))->first(fn ($fk) => $fk['columns'] === ['vendor_id']);
        $this->assertNotNull($fks, 'orders.vendor_id foreign key survived');
        $this->assertSame('vendors', $fks['foreign_table']);
        $this->assertSame('cascade', $fks['on_delete']);

        $col = collect(Schema::getColumns('orders'))->firstWhere('name', 'vendor_id');
        $this->assertTrue($col['nullable']);

        $indexed = collect(Schema::getIndexes('orders'))->pluck('columns')->map(fn ($c) => implode(',', $c));
        $this->assertTrue($indexed->contains('idempotency_scope,idempotency_key'), 'idempotency unique index preserved');
        $this->assertTrue($indexed->contains(fn ($c) => str_contains($c, 'payment_reference') || str_contains($c, 'gateway_transaction_id')), 'payment single-use index preserved');
    }

    // =====================================================================
    // PaymentService utility branch
    // =====================================================================

    public function test_completion_is_idempotent_across_callback_webhook_and_reconciliation_races(): void
    {
        Queue::fake();
        $this->fake([]);
        $u = $this->makeOrder(['paid' => false]);
        $order = Order::find($u->order_id);
        $this->prove($order);

        $payments = app(PaymentService::class);
        $this->assertTrue($payments->completeOrder($order->fresh()));
        $this->assertTrue($payments->completeOrder($order->fresh()));     // callback + webhook racing
        $this->assertTrue($payments->completeOrder(Order::find($order->id)));

        Queue::assertPushed(SubmitUtilityBillPayment::class, 1);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(FulfillmentStatus::QUEUED, $u->fresh()->fulfillment_status);
        $this->assertSame(0, \App\Models\Transaction::count());
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_an_unproven_payment_cannot_complete_or_dispatch(): void
    {
        Queue::fake();
        $u = $this->makeOrder();                   // integrity = pending_verification
        $order = Order::find($u->order_id);

        $this->assertFalse(app(PaymentService::class)->completeOrder($order));

        Queue::assertNothingPushed();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(FulfillmentStatus::AWAITING_PAYMENT, $u->fresh()->fulfillment_status);
    }

    public function test_fulfillment_is_dispatched_only_after_the_surrounding_transaction_commits(): void
    {
        // Queue::fake() does not model after-commit deferral, so use the real database queue.
        config(['queue.default' => 'database']);
        $u = $this->makeOrder();
        $order = Order::find($u->order_id);
        $this->prove($order);
        DB::table('jobs')->delete();

        DB::transaction(function () use ($order) {
            app(PaymentService::class)->completeOrder($order->fresh());
            $this->assertSame(0, DB::table('jobs')->count(), 'nothing queued while the transaction is still open');
        });

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('SubmitUtilityBillPayment', (string) DB::table('jobs')->value('payload'));
    }

    public function test_reconciliation_can_safely_complete_a_utility_order_once(): void
    {
        Queue::fake();
        $u = $this->makeOrder();
        $order = Order::find($u->order_id);
        $order->forceFill(['payment_reference' => 'UB-RECON-1', 'created_at' => now()->subHour()])->save();

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10000, 'currency' => 'GHS', 'reference' => 'UB-RECON-1']])]);
        \App\Models\PaymentGatewayConfig::create([
            'gateway_name' => \App\Models\PaymentGatewayConfig::GATEWAY_PAYSTACK, 'gateway_type' => \App\Models\PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => \App\Models\PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk', 'secret_key' => 'sk', 'payment_url' => 'https://api.paystack.co'], 'supported_features' => [],
        ]);

        $recon = app(PaymentReconciliationService::class);
        $recon->reconcile($order->fresh());
        $recon->reconcile($order->fresh());

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('verified', $order->fresh()->payment_integrity_status);
        Queue::assertPushed(SubmitUtilityBillPayment::class, 1);
        $this->assertSame(0, \App\Models\Transaction::count());
    }

    public function test_no_normal_vendor_or_reseller_earnings_come_from_a_utility_payment(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 0]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['vendor' => $vendor, 'paid' => true]);
        $order = Order::find($u->order_id);

        $this->assertSame('0.00', (string) $vendor->fresh()->wallet_balance);        // not even the 98% product split
        $this->assertNull($order->owner_earning);
        $this->assertNull($order->reseller_earning);
        $this->assertNull($order->platform_commission);
        $this->assertFalse((bool) $order->is_reseller_order);
        $this->assertSame(0, \App\Models\Transaction::count());
        $this->assertSame(0, WalletLedger::count());
    }

    // =====================================================================
    // Admin economics warning
    // =====================================================================

    public function test_admin_sees_a_warning_when_vendor_commission_exceeds_observed_provider_economics(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        UtilityBillSettings::save(true, null);
        UtilityBillerConfig::create(['biller_key' => 'dstv', 'is_enabled' => true, 'commission_type' => 'percentage', 'commission_value' => '1']);

        // No completed orders yet: economics unknown, no warning, not blocked.
        $this->get(route('admin.utility-bills.settings'))->assertOk()
            ->assertSee('No completed-sale data yet')->assertDontSee('could cost XTRA4U more');

        // Provider has paid 0.40% on completed DSTV orders; the vendor is configured at 1%.
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-E1'))]);
        $u = $this->makeOrder(['paid' => true, 'amount' => '100.00', 'biller' => 'dstv']);
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::COMPLETED, 'provider_commission_earned' => '0.40'])->save();

        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->get(route('admin.utility-bills.settings'))->assertOk()
            ->assertSee('0.40%')->assertSee('could cost XTRA4U more')->assertSee('not guaranteed profit');

        // Configuring it above provider economics is NOT blocked.
        $this->put(route('admin.utility-bills.settings.update'), ['enabled' => 1, 'billers' => ['dstv' => ['is_enabled' => 1, 'commission_type' => 'percentage', 'commission_value' => '2']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('2.0000', (string) UtilityBillerConfig::where('biller_key', 'dstv')->value('commission_value'));

        // At or below observed: no warning.
        UtilityBillerConfig::where('biller_key', 'dstv')->update(['commission_value' => '0.3']);
        $this->get(route('admin.utility-bills.settings'))->assertDontSee('could cost XTRA4U more');
    }

    // =====================================================================
    // Scheduler / queue safety
    // =====================================================================

    public function test_the_scheduler_does_not_pile_up_duplicate_jobs_when_workers_are_down(): void
    {
        Queue::fake();
        $this->fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('x')]);
        $u = $this->makeOrder(['paid' => true]);
        Queue::fake();                                               // forget the jobs created above
        $u->refresh()->forceFill(['next_submit_at' => now()->subMinute(), 'fulfillment_status' => FulfillmentStatus::QUEUED])->save();

        for ($i = 0; $i < 5; $i++) {
            app(UtilityBillSweeper::class)->run();
        }

        Queue::assertPushed(SubmitUtilityBillPayment::class, 1);
    }

    public function test_status_polling_is_bounded_per_run_and_stops_at_the_rate_limit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-P'.$i))]);
            $this->makeOrder(['paid' => true])->forceFill(['next_status_check_at' => now()->subMinute()])->save();
        }

        config(['utility_bills.rate.status_per_minute' => 3]);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('processing', 'UTIL-DSTV-P0'))]);
        RateLimiter::clear('utility-bills:provider:status');

        app(UtilityBillSweeper::class)->run(submitLimit: 5, pollLimit: 20);

        // Never more provider status calls than our budget, however many orders are due.
        $this->assertLessThanOrEqual(3, Http::recorded(fn (Request $r) => str_contains($r->url(), '/utilities/orders/'))->count());
    }

    // =====================================================================
    // Provider request contract (exact wire format)
    // =====================================================================

    public function test_requests_match_the_documented_wire_contract(): void
    {
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['account_name' => null, 'meters' => [['name' => 'A B', 'meterNumber' => '3701234567', 'outstanding' => 1]]]]),
            self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-ECG-1')),
            self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('pending', 'UTIL-ECG-1')),
        ]);
        $p = app(KingFlexyUtilityProvider::class);

        $p->catalog();
        $p->lookup('ecg', '0551617309', '0551617309');
        $p->pay('ecg', '3701234567', '50.00', 'XU-UBREF-A1', '0551617309');
        $p->status('UTIL-ECG-1');

        Http::assertSent(function (Request $r) {
            // Raw Commission Services key in Authorization (no "Bearer"), JSON Accept.
            if ($r->header('Authorization') !== ['kf_cs_live_testkey123'] || ! str_contains(implode(',', $r->header('Accept')), 'application/json')) {
                return false;
            }

            return true;
        });

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === self::BASE.'/utilities/billers');

        // ECG lookup: biller + account(=phone) + phone, as documented ("the query actually runs on phone").
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/utilities/lookup')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET' && $q === ['biller' => 'ecg', 'account' => '0551617309', 'phone' => '0551617309'];
        });

        // Pay: JSON body, account = METER (not phone), phone present for ecg, our idempotency reference, numeric amount.
        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/utilities/pay')) {
                return false;
            }

            return str_contains($r->header('Content-Type')[0] ?? '', 'application/json')
                && $r->data() === ['biller' => 'ecg', 'account' => '3701234567', 'amount' => 50.0, 'reference' => 'XU-UBREF-A1', 'phone' => '0551617309'];
        });

        // Status is queried by the PROVIDER's reference.
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === self::BASE.'/utilities/orders/UTIL-ECG-1');
    }

    public function test_non_ecg_pay_omits_phone_when_not_required_and_references_are_valid(): void
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['paid' => true]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,64}$/', $u->fresh()->provider_request_reference);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/utilities/pay') && ! array_key_exists('phone', $r->data()));
    }
}
