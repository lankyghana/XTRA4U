<?php

namespace Tests\Feature\UtilityBills\MySql;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\UtilityBillOrder;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\ProviderRateBudget;
use App\Support\PaymentFailureTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Real multi-process concurrency against MySQL/InnoDB, which SQLite cannot reproduce (no row
 * locks; one writer for the whole file). Opt-in: skipped unless the suite runs on MySQL with
 * UTILITY_BILLS_MYSQL_TESTS=1, e.g.
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=xtra4u_race DB_USERNAME=root \
 *   DB_PASSWORD= UTILITY_BILLS_MYSQL_TESTS=1 php artisan test tests/Feature/UtilityBills/MySql
 *
 * The database must already be migrated. Rows are committed (no RefreshDatabase) so the worker
 * processes can see them; each test clears the Utility Bills tables it uses first.
 */
class UtilityBillMySqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || ! env('UTILITY_BILLS_MYSQL_TESTS')) {
            $this->markTestSkipped('MySQL concurrency tests are opt-in (UTILITY_BILLS_MYSQL_TESTS=1 on a MySQL connection).');
        }

        config(['queue.default' => 'sync', 'services.kingflexy_utilities.api_key' => 'kf_cs_live_testkey123',
            'services.kingflexy_utilities.base_url' => 'https://api.kingflexygh.com/api/v2']);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['utility_bill_events', 'utility_bill_orders', 'orders', 'payment_gateway_configs', 'cache', 'cache_locks'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_123', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);
    }

    // ------------------------------------------------------------------
    // Payment verification / webhook race (Test C)
    // ------------------------------------------------------------------

    public function test_failed_verify_waits_for_a_concurrent_webhook_holding_the_row_lock_and_never_downgrades(): void
    {
        $u = $this->unpaidOrder();
        $ref = $u->order->payment_reference;
        $flag = $this->tempFile();

        // The webhook process takes the row lock and keeps it ~1.5 s before committing "paid".
        $webhook = $this->spawn('complete', ['order_id' => $u->order_id, 'flag' => $flag, 'hold_ms' => 1500]);
        $this->waitForFile($flag);

        // Meanwhile the browser verify reads the order (unpaid: the webhook has not committed) and the
        // gateway answers "failed". Its conditional UPDATE must block on the lock, then see "paid".
        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'reference' => $ref]])]);
        $started = microtime(true);
        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()
            ->assertJson(['status' => 'success', 'redirect' => $u->statusUrl()]);
        $waited = microtime(true) - $started;

        $result = $this->finish($webhook);
        $this->assertTrue($result['completed'] ?? false, json_encode($result));
        $this->assertGreaterThan(0.5, $waited, 'verify should have waited on the webhook row lock');

        $order = Order::query()->findOrFail($u->order_id);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->payment_completed_at);
        $this->assertSame('verified', $order->payment_integrity_status);
        $this->assertSame(1, $u->events()->where('kind', 'payment_confirmed')->count());
        $this->assertSame(1, $u->events()->where('kind', 'submit_claimed')->count());
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fresh()->fulfillment_status);
    }

    public function test_many_concurrent_failure_writers_and_one_completion_never_end_paid_to_failed(): void
    {
        $u = $this->unpaidOrder();

        // Four "failed" verifiers hammer the transition from separate processes while this process
        // settles the order through the real guard + completeOrder path.
        $failers = [];
        for ($i = 0; $i < 4; $i++) {
            $failers[] = $this->spawn('fail', ['order_id' => $u->order_id, 'run_ms' => 3500]);
        }
        usleep(1_200_000);

        Http::fake(['*/utilities/pay' => Http::response(['success' => true, 'data' => ['reference' => 'UTIL-RACE-2', 'status' => 'pending']])]);
        $order = Order::query()->findOrFail($u->order_id);
        $integrity = app(PaymentIntegrityGuard::class)->guard($order, ['success' => true, 'data' => ['status' => 'success', 'amount' => 100.0, 'currency' => 'GHS', 'reference' => $order->payment_reference, 'id' => 777]], 'paystack');
        $order->amount_paid = $integrity->confirmedAmount;
        $this->assertTrue(app(PaymentService::class)->completeOrder($order));
        $paidAt = microtime(true);

        $outcomes = collect($failers)->flatMap(fn ($p) => $this->finish($p)['outcomes'] ?? []);
        $this->assertNotEmpty($outcomes);

        // No writer may have applied "failed" after the order was settled.
        $lateFailures = $outcomes->filter(fn ($o) => $o[1] === PaymentFailureTransition::FAILED && $o[0] > $paidAt);
        $this->assertCount(0, $lateFailures);
        $this->assertTrue($outcomes->contains(fn ($o) => $o[1] === PaymentFailureTransition::PAID));

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->payment_completed_at);
        $this->assertSame(1, $u->events()->where('kind', 'payment_confirmed')->count());
        $this->assertSame(1, $u->events()->where('kind', 'submit_claimed')->count());
    }

    // ------------------------------------------------------------------
    // Shared provider budget across processes (database cache store)
    // ------------------------------------------------------------------

    public function test_the_pay_budget_is_atomic_across_processes(): void
    {
        config(['utility_bills.rate.pay_per_minute' => 5]);
        $startAt = microtime(true) + 3;

        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $workers[] = $this->spawn('budget', ['endpoint' => 'pay', 'attempts' => 5, 'start_at' => $startAt]);
        }
        $granted = collect($workers)->sum(fn ($p) => $this->finish($p)['granted']);

        // 30 simultaneous attempts from 6 processes; exactly the budget got through.
        $this->assertSame(5, $granted);
        config(['cache.default' => 'database']);
        $this->assertSame(5, app(ProviderRateBudget::class)->used('pay'));
    }

    public function test_concurrent_submit_workers_never_exceed_the_pay_budget_or_pay_an_order_twice(): void
    {
        Queue::fake();   // keep the orders queued: only the workers below submit them
        $orders = collect(range(1, 10))->map(function () {
            $u = $this->unpaidOrder();
            $order = $u->order;
            $integrity = app(PaymentIntegrityGuard::class)->guard($order, ['success' => true, 'data' => ['status' => 'success', 'amount' => 100.0, 'currency' => 'GHS', 'reference' => $order->payment_reference, 'id' => 'T'.$u->id]], 'paystack');
            $order->amount_paid = $integrity->confirmedAmount;
            app(PaymentService::class)->completeOrder($order);

            return $u->fresh();
        });
        $this->assertTrue($orders->every(fn ($u) => $u->fulfillment_status === FulfillmentStatus::QUEUED));

        $ids = $orders->pluck('id')->all();
        $startAt = microtime(true) + 3;
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            // Every worker walks the same orders (rotated), as overlapping sweeps/retries would.
            $mine = array_merge(array_slice($ids, $i * 2), array_slice($ids, 0, $i * 2));
            $workers[] = $this->spawn('submit', ['ids' => $mine, 'pay_per_minute' => 3, 'start_at' => $startAt]);
        }
        $payCalls = collect($workers)->sum(fn ($p) => $this->finish($p)['pay_calls']);

        $this->assertSame(3, $payCalls, 'exactly the pay budget reached the provider');
        $accepted = DB::table('utility_bill_events')->where('kind', 'provider_accepted')->pluck('utility_bill_order_id');
        $this->assertCount(3, $accepted);
        $this->assertCount(3, $accepted->unique(), 'no order was paid twice');
        $this->assertSame(3, UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::PROVIDER_PENDING)->count());
        $this->assertSame(7, UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::QUEUED)->where('submit_attempts', 0)->count(),
            'held-back orders stay queued and uncounted');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function unpaidOrder(): UtilityBillOrder
    {
        $order = Order::create([
            'recipient_phone_number' => '0551617309', 'mobile_money_number' => '0551617309',
            'service_purchased' => 'Utility Bill: DSTV', 'amount_paid' => '100.00', 'base_price' => '100.00',
            'markup_price' => '0.00', 'expected_amount' => '100.00', 'currency' => 'GHS', 'pricing_snapshot_at' => now(),
            'vendor_id' => null, 'status' => 'Pending', 'payment_status' => 'unpaid', 'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-'.uniqid(),
        ]);

        $u = new UtilityBillOrder;
        $u->forceFill([
            'public_ref' => UtilityBillOrder::newPublicRef(), 'access_token' => UtilityBillOrder::newAccessToken(),
            'order_id' => $order->id, 'vendor_id' => null, 'biller_key' => 'dstv', 'biller_label' => 'DSTV',
            'account_number' => '7041234567', 'account_name' => 'KWAME MENSAH', 'bill_amount' => '100.00',
            'expected_amount' => '100.00', 'currency' => 'GHS', 'fulfillment_status' => FulfillmentStatus::AWAITING_PAYMENT,
            'commission_basis' => 'bill_face_value', 'commission_basis_amount' => '0.00', 'commission_amount' => '0.00',
            'commission_status' => 'none',
        ])->save();

        return $u->fresh(['order']);
    }

    /** @return array{proc: resource, pipes: array} */
    protected function spawn(string $mode, array $args): array
    {
        $cmd = [PHP_BINARY, __DIR__.'/race_worker.php', $mode, json_encode($args)];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($proc);

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    protected function finish(array $p): array
    {
        $stdout = stream_get_contents($p['pipes'][1]);
        $stderr = stream_get_contents($p['pipes'][2]);
        proc_close($p['proc']);

        $line = trim((string) collect(explode("\n", trim((string) $stdout)))->last());
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, "worker produced no result.\nstdout: {$stdout}\nstderr: {$stderr}");

        return $decoded;
    }

    protected function tempFile(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ub-race-'.uniqid().'.flag';
        @unlink($path);

        return $path;
    }

    protected function waitForFile(string $path, float $timeout = 20.0): void
    {
        $until = microtime(true) + $timeout;
        while (! file_exists($path)) {
            $this->assertLessThan($until, microtime(true), 'worker never signalled');
            usleep(20_000);
        }
    }
}
