<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\SandboxOrder;
use App\Models\Order;
use App\Services\DeveloperApi\Sandbox\SandboxCleanup;
use App\Services\DeveloperApi\Webhooks\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The documented production retention is 30 days (DEVELOPER_API_SANDBOX_RETENTION_DAYS=30). These tests pin
 * what `developer-sandbox:prune --dry-run` reports at exactly that setting, over data of mixed ages, and prove
 * a dry run changes nothing. Real deletion is covered separately in SandboxIsolationTest.
 */
class SandboxRetentionTest extends SandboxTestCase
{
    /**
     * Four sandbox orders aged 5, 29, 31 and 90 days, plus an old PRODUCTION order/quote/event.
     *
     * @return array{w:array<string,mixed>, expiredOrderIds:list<int>, keptOrderIds:list<int>, expiredQuoteIds:list<int>}
     */
    private function mixedAges(): array
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        // A webhook so sandbox events/deliveries exist and can be counted.
        app(WebhookService::class)->create($w['app'], 'hook', 'https://hooks.example.com/in', ['order.created'], null, 'sandbox');

        $ids = [];
        foreach ([5, 29, 31, 90] as $i => $_) {
            $ids[] = $this->sandboxOrderId($w['key'], $w['offer'], "idem-{$i}");
        }

        $orders = SandboxOrder::orderBy('id')->get();
        $quotes = ApiQuote::where('environment', 'sandbox')->orderBy('id')->get();
        $this->assertCount(4, $orders);
        $this->assertCount(4, $quotes);

        foreach ([5, 29, 31, 90] as $i => $days) {
            $then = now()->subDays($days);
            DB::table('developer_sandbox_orders')->where('id', $orders[$i]->id)->update(['created_at' => $then, 'updated_at' => $then]);
            DB::table('api_quotes')->where('id', $quotes[$i]->id)->update(['created_at' => $then, 'updated_at' => $then]);
        }

        // Production data that is far older than the cutoff and must be invisible to the sandbox cleanup.
        $live = $this->liveKey($w['app']);
        $this->order($live, $this->quote($live, $w['offer'])->json('data.reference'), 'p-old')->assertCreated();
        $old = now()->subDays(400);
        DB::table('orders')->update(['created_at' => $old, 'updated_at' => $old]);
        DB::table('api_quotes')->where('environment', 'production')->update(['created_at' => $old, 'updated_at' => $old]);
        // A production event deliberately sharing an order id with the oldest sandbox order.
        DeveloperEvent::create([
            'event_id' => 'evt_prod_overlap', 'application_id' => $w['app']->id, 'order_id' => $orders[3]->id,
            'type' => 'order.created', 'environment' => 'production', 'payload' => [], 'occurred_at' => $old,
        ]);

        return [
            'w' => $w,
            'expiredOrderIds' => [$orders[2]->id, $orders[3]->id],
            'keptOrderIds' => [$orders[0]->id, $orders[1]->id],
            'expiredQuoteIds' => [$quotes[2]->id, $quotes[3]->id],
        ];
    }

    /** Row counts of everything cleanup could conceivably touch. @return array<string,int> */
    private function counts(): array
    {
        return collect([
            'developer_sandbox_orders', 'developer_sandbox_payments', 'developer_sandbox_order_events', 'developer_events',
            'developer_webhook_deliveries', 'developer_webhook_attempts', 'api_quotes', 'orders', 'developer_api_keys',
        ])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_the_documented_production_retention_of_30_days_is_accepted_above_the_floor(): void
    {
        config(['developer_api.sandbox.retention_days' => 30]);

        $this->assertSame(30, app(SandboxCleanup::class)->effectiveDays());
    }

    public function test_dry_run_at_30_days_reports_only_expired_sandbox_records_and_deletes_nothing(): void
    {
        $d = $this->mixedAges();
        config(['developer_api.sandbox.retention_days' => 30]);
        $before = $this->counts();

        $expectedEvents = DeveloperEvent::where('environment', 'sandbox')->whereIn('order_id', $d['expiredOrderIds'])->count();
        $this->assertGreaterThan(0, $expectedEvents, 'fixture must contain sandbox events for the expired orders');

        $r = app(SandboxCleanup::class)->run(null, dryRun: true);

        $this->assertTrue($r['dry_run']);
        $this->assertSame(30, $r['days']);
        $this->assertSame(2, $r['orders'], 'only the 31 and 90 day old sandbox orders are expired; 5 and 29 are kept');
        $this->assertSame($expectedEvents, $r['events'], 'the production event sharing an order id is not counted');
        $this->assertSame(2, $r['quotes'], 'only expired SANDBOX quotes; the 400-day-old production quote is not counted');

        $this->assertSame($before, $this->counts(), 'a dry run must not delete or change any row');
        $this->assertSame(array_merge($d['keptOrderIds'], $d['expiredOrderIds']), SandboxOrder::orderBy('id')->pluck('id')->all());
    }

    public function test_the_prune_command_dry_run_prints_the_same_numbers_and_keeps_every_row(): void
    {
        $this->mixedAges();
        config(['developer_api.sandbox.retention_days' => 30]);
        $before = $this->counts();

        $this->assertSame(0, Artisan::call('developer-sandbox:prune', ['--dry-run' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('Would delete sandbox data idle since', $out);
        $this->assertStringContainsString('orders=2 ', $out);
        $this->assertStringContainsString('quotes=2', $out);
        $this->assertStringNotContainsString('Deleted', $out);

        $this->assertSame($before, $this->counts());
    }

    public function test_the_boundary_is_strictly_older_than_the_cutoff(): void
    {
        $this->mixedAges();
        config(['developer_api.sandbox.retention_days' => 30]);

        // 29 days old is inside the window, 31 is outside; moving the cutoff to 28 pulls the 29 day order in.
        $this->assertSame(2, app(SandboxCleanup::class)->run(null, dryRun: true)['orders']);
        $this->assertSame(3, app(SandboxCleanup::class)->run(28, dryRun: true)['orders']);
    }

    public function test_production_orders_are_never_counted_however_old(): void
    {
        $this->mixedAges();
        config(['developer_api.sandbox.retention_days' => 30]);

        $this->assertGreaterThan(0, Order::where('created_at', '<', now()->subDays(30))->count(), 'fixture: an old production order exists');
        $r = app(SandboxCleanup::class)->run(null, dryRun: true);

        $this->assertSame(2, $r['orders']);
    }

    public function test_cleanup_stays_off_without_a_configured_retention_even_for_dry_runs(): void
    {
        $this->mixedAges();
        $before = $this->counts();

        $this->assertNull(config('developer_api.sandbox.retention_days'));
        $this->artisan('developer-sandbox:prune --dry-run')->expectsOutputToContain('disabled')->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }
}
