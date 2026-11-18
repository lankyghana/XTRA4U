<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApiRequest;
use App\Models\Developer\DeveloperAuditEvent;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookAttempt;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Developer\SandboxOrder;
use App\Models\Order;
use App\Services\DeveloperApi\Sandbox\SandboxCleanup;
use App\Services\DeveloperApi\Webhooks\OrderEventDetector;
use App\Services\DeveloperApi\Webhooks\WebhookFanout;
use App\Services\DeveloperApi\Webhooks\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Structural and data-level proof that the sandbox cannot reach production: what the sandbox code is allowed
 * to reference, what the rest of the platform is allowed to know about sandbox tables, and what retention
 * may delete.
 */
class SandboxIsolationTest extends SandboxTestCase
{
    // ── architecture: the sandbox code has no path to production services ────

    /** @return list<string> */
    private function sandboxFiles(): array
    {
        $files = glob(app_path('Services/DeveloperApi/Sandbox/*.php'));
        $files[] = app_path('Http/Controllers/DeveloperApi/SandboxController.php');
        $files[] = app_path('Http/Resources/DeveloperApi/SandboxOrderResource.php');
        $files[] = app_path('Http/Requests/DeveloperApi/SimulateSandboxOrderRequest.php');
        $files[] = app_path('Http/Middleware/DeveloperApi/RequireSandboxEnvironment.php');
        $files[] = app_path('Console/Commands/DeveloperSandboxPrune.php');
        foreach (glob(app_path('Models/Developer/Sandbox*.php')) as $f) {
            $files[] = $f;
        }

        return $files;
    }

    /** PHP source with comments removed, so docblocks that explain what is NOT used do not trip the scan. */
    private function code(string $file, bool $stripStrings = false): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($file)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($stripStrings && is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    public function test_the_sandbox_code_never_references_production_payment_fulfilment_wallet_or_messaging_code(): void
    {
        $forbidden = [
            'GatewayManager', 'PaymentIntegrityGuard', 'PaymentFailureTransition', 'WalletService', 'WalletLedger', 'WalletTopup',
            'SmsService', 'AffiliateChainService', 'ExternalFulfillment', 'ProcessExternalFulfillment', 'OrderCompleted',
            'ResultChecker', 'UtilityBill', 'DeveloperEventRecorder', 'OrderEventDetector', 'PaymentCallbackController',
            'Illuminate\\Support\\Facades\\Mail', 'Illuminate\\Support\\Facades\\Http', 'Illuminate\\Support\\Facades\\Notification',
            'App\\Models\\Order;', 'App\\Models\\Transaction', 'App\\Models\\Vendor;', 'App\\Models\\Product;', 'App\\Models\\ResellerProduct',
        ];
        // Standalone class names that must not appear as whole words (Sandbox* classes legitimately contain "PaymentService").
        $words = ['PaymentService', 'ApiPaymentService', 'ApiOrderService', 'Order', 'Transaction'];

        $files = $this->sandboxFiles();
        $this->assertGreaterThan(8, count($files));

        foreach ($files as $file) {
            $code = $this->code($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, basename($file)." references {$needle}");
            }
            $noStrings = $this->code($file, true);
            foreach ($words as $w) {
                $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z_])'.preg_quote($w, '/').'(?![A-Za-z_])/', $noStrings, basename($file)." references {$w}");
            }
            // The only tables the sandbox may name outside its own are the shared event pipeline and quote store.
            // No query builder / schema call may NAME a production table (array keys that happen to be called "orders" are fine).
            foreach (['orders', 'transactions', 'wallet_ledgers', 'wallet_topups', 'payment_gateway_configs', 'products', 'reseller_products', 'vendors'] as $table) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(table|from|join|leftJoin|rightJoin|constrained|on|references)\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/',
                    $code,
                    basename($file)." queries the production table {$table}"
                );
            }
        }
    }

    public function test_nothing_outside_the_developer_platform_knows_the_sandbox_tables_exist(): void
    {
        // Earnings, sales, commission, wallet and reporting code must be unable to include sandbox data.
        $allowed = ['Services/DeveloperApi/', 'Http/Controllers/DeveloperApi/', 'Http/Controllers/Developers/', 'Http/Resources/DeveloperApi/',
            'Http/Requests/DeveloperApi/', 'Http/Middleware/DeveloperApi/', 'Models/Developer/', 'Console/Commands/DeveloperSandboxPrune.php'];

        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            $src = file_get_contents($file->getPathname());
            if (! preg_match('/developer_sandbox|SandboxOrder|SandboxPayment|Sandbox(Order)?Event\b/', $src)) {
                continue;
            }
            if (! collect($allowed)->contains(fn ($a) => str_starts_with($rel, $a))) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, 'sandbox data is referenced outside the developer platform');
    }

    public function test_the_production_controllers_branch_on_the_environment_before_anything_else(): void
    {
        foreach (['OrderController' => ['store', 'show', 'status'], 'PaymentController' => ['store', 'transaction']] as $class => $methods) {
            $src = file_get_contents(app_path("Http/Controllers/DeveloperApi/{$class}.php"));
            foreach ($methods as $m) {
                $this->assertMatchesRegularExpression(
                    '/public function '.$m.'\([^)]*\)\s*\{\s*(\/\/[^\n]*\n\s*)*if \(ApiEnvironment::isSandbox\(\$request\)\)/s',
                    $src,
                    "{$class}::{$m} must test the environment first"
                );
            }
        }
    }

    public function test_the_sandbox_routes_cannot_be_reached_without_the_sandbox_gate(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), '/sandbox/') || str_ends_with($route->uri(), '/sandbox')) {
                $this->assertContains('api.sandbox', $route->gatherMiddleware(), $route->uri().' lacks the sandbox environment gate');
                $this->assertContains('api.key', $route->gatherMiddleware());
            }
        }
        // Every authenticated API route goes through the environment check.
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/') && $route->uri() !== 'api/v1/health') {
                $this->assertContains('api.env', $route->gatherMiddleware(), $route->uri().' lacks api.env');
            }
        }
    }

    // ── data: the sandbox leaves no trace in production ──────────────────────

    public function test_sandbox_activity_leaves_every_production_table_untouched(): void
    {
        $w = $this->sandboxWorld();
        $before = $this->productionFingerprint($w['vendor']);
        $tables = ['orders', 'transactions', 'wallet_ledgers', 'wallet_topups', 'result_checker_orders', 'afa_registrations', 'payment_gateway_configs'];
        $counts = fn () => collect($tables)->filter(fn ($t) => Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $tablesBefore = $counts();

        foreach (['k1' => 'success', 'k2' => 'payment_failed', 'k3' => 'fulfillment_failed'] as $key => $scenario) {
            $id = $this->sandboxOrderId($w['key'], $w['offer'], $key);
            $this->startPayment($w['key'], $id);
            $this->simulate($w['key'], $id, ['scenario' => $scenario])->assertOk();
        }

        $this->assertSame($tablesBefore, $counts());
        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
        $this->assertSame(3, SandboxOrder::count());
        $this->assertSame(0, app(OrderEventDetector::class)->sweep());
    }

    public function test_sandbox_inventory_and_result_checker_pins_are_never_allocated(): void
    {
        $w = $this->sandboxWorld();
        $stock = collect(['result_checker_pins', 'result_checker_stock', 'inventory'])->filter(fn ($t) => Schema::hasTable($t));
        $before = $stock->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id);
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk();

        $this->assertSame($before, $stock->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all());
        $this->assertSame((string) $w['product']->fresh()->price, (string) $w['product']->price);
    }

    // ── retention ────────────────────────────────────────────────────────────

    /** Ages every sandbox row to $days old, without touching production rows. */
    private function age(int $days): void
    {
        $then = now()->subDays($days);
        DB::table('developer_sandbox_orders')->update(['created_at' => $then, 'updated_at' => $then]);
        DB::table('api_quotes')->where('environment', 'sandbox')->update(['created_at' => $then, 'updated_at' => $then]);
    }

    /** A world with a full sandbox history AND production data that cleanup must never touch. @return array<string,mixed> */
    private function populated(): array
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        app(WebhookService::class)->create($w['app'], 'test', 'https://hooks.example.com/in', ['order.created', 'order.completed'], null, 'sandbox');
        $prodHook = app(WebhookService::class)->create($w['app'], 'test', 'https://other.example.org/live', ['order.created'], null, 'production')['webhook'];

        $id = $this->sandboxOrderId($w['key'], $w['offer'], 's1');
        $this->startPayment($w['key'], $id);
        $this->simulate($w['key'], $id, ['scenario' => 'success']);
        $this->quote($w['key'], $w['offer'])->assertCreated();            // an unused sandbox quote

        // Production data that happens to share ids/ages with the sandbox rows.
        $live = $this->liveKey($w['app']);
        $this->order($live, $this->quote($live, $w['offer'])->json('data.reference'), 'p1')->assertCreated();
        app(OrderEventDetector::class)->sweep();
        app(WebhookFanout::class)->run();
        DeveloperAuditEvent::create(['actor' => 'vendor:1', 'application_id' => $w['app']->id, 'action' => 'key.created']);

        return $w + ['prodHook' => $prodHook];
    }

    private function productionSnapshot(): array
    {
        return [
            'orders' => Order::count(),
            'keys' => DeveloperApiKey::count(),
            'audit' => DeveloperAuditEvent::count(),
            'requests' => DeveloperApiRequest::count(),
            'webhooks' => DeveloperWebhook::count(),
            'prod_events' => DeveloperEvent::where('environment', 'production')->count(),
            'prod_deliveries' => DeveloperWebhookDelivery::whereIn('event_id', DeveloperEvent::where('environment', 'production')->select('id'))->count(),
            'prod_quotes' => ApiQuote::where('environment', 'production')->count(),
        ];
    }

    public function test_cleanup_is_disabled_unless_a_retention_is_configured(): void
    {
        $this->populated();
        $this->age(400);
        $sandbox = SandboxOrder::count();

        $this->assertNull(config('developer_api.sandbox.retention_days'));
        $this->artisan('developer-sandbox:prune')->expectsOutputToContain('disabled')->assertSuccessful();

        $this->assertSame($sandbox, SandboxOrder::count());
    }

    public function test_cleanup_deletes_only_old_sandbox_data_and_never_production_data(): void
    {
        $this->populated();
        $prodBefore = $this->productionSnapshot();
        $this->assertGreaterThan(0, DeveloperEvent::where('environment', 'sandbox')->count());
        $this->assertGreaterThan(0, DeveloperWebhookDelivery::count());
        $this->age(30);

        config(['developer_api.sandbox.retention_days' => 14]);
        $r = app(SandboxCleanup::class)->run();

        $this->assertTrue($r['enabled']);
        $this->assertSame(1, $r['orders']);
        $this->assertGreaterThan(0, $r['events']);
        $this->assertGreaterThan(0, $r['deliveries']);
        $this->assertSame(2, $r['quotes']);   // the consumed one and the unused one

        // Gone: every sandbox trace.
        $this->assertSame(0, SandboxOrder::count());
        $this->assertSame(0, DB::table('developer_sandbox_payments')->count());
        $this->assertSame(0, DB::table('developer_sandbox_order_events')->count());
        $this->assertSame(0, DeveloperEvent::where('environment', 'sandbox')->count());
        $this->assertSame(0, ApiQuote::where('environment', 'sandbox')->count());
        $this->assertSame(0, DeveloperWebhookAttempt::count() - DeveloperWebhookAttempt::whereIn('delivery_id', DeveloperWebhookDelivery::select('id'))->count(), 'no orphaned attempts');

        // Untouched: everything production, plus keys, audit, request logs and the webhook endpoints themselves.
        $this->assertSame($prodBefore, $this->productionSnapshot());
    }

    public function test_cleanup_never_deletes_recent_sandbox_data_or_goes_below_the_safety_floor(): void
    {
        $this->populated();
        $this->age(3);                                   // 3 days old

        // Even an aggressive request is clamped to the floor (7 days), so 3-day-old data survives.
        $this->assertSame(7, app(SandboxCleanup::class)->effectiveDays(1));
        $this->artisan('developer-sandbox:prune --days=1')->expectsOutputToContain('safety floor')->assertSuccessful();
        $this->assertSame(1, SandboxOrder::count());

        config(['developer_api.sandbox.retention_days' => 1]);
        app(SandboxCleanup::class)->run();
        $this->assertSame(1, SandboxOrder::count());

        // Recent activity keeps an old order alive: only "last touched" matters.
        $this->age(60);
        DB::table('developer_sandbox_orders')->update(['updated_at' => now()->subDay()]);
        app(SandboxCleanup::class)->run(30);
        $this->assertSame(1, SandboxOrder::count());
    }

    public function test_cleanup_is_idempotent_and_supports_a_dry_run(): void
    {
        $this->populated();
        $this->age(30);

        $dry = app(SandboxCleanup::class)->run(14, dryRun: true);
        $this->assertSame(1, $dry['orders']);
        $this->assertSame(1, SandboxOrder::count(), 'a dry run deletes nothing');
        $this->assertGreaterThan(0, DeveloperEvent::where('environment', 'sandbox')->count());

        $first = app(SandboxCleanup::class)->run(14);
        $second = app(SandboxCleanup::class)->run(14);

        $this->assertSame(1, $first['orders']);
        $this->assertSame(0, $second['orders']);
        $this->assertSame(0, $second['events']);
        $this->assertSame(0, $second['quotes']);
    }

    public function test_cleanup_keeps_a_production_event_that_shares_an_order_id_with_a_sandbox_order(): void
    {
        $this->populated();
        $sandboxOrderId = SandboxOrder::firstOrFail()->id;
        // Force the overlap instead of relying on auto-increment behaviour, which differs per engine.
        if (! DeveloperEvent::where('environment', 'production')->where('order_id', $sandboxOrderId)->exists()) {
            DeveloperEvent::create([
                'event_id' => 'evt_prod_overlap', 'application_id' => SandboxOrder::firstOrFail()->application_id, 'order_id' => $sandboxOrderId,
                'type' => 'order.created', 'environment' => 'production', 'payload' => [], 'occurred_at' => now(),
            ]);
        }
        $this->assertTrue(DeveloperEvent::where('environment', 'production')->where('order_id', $sandboxOrderId)->exists(), 'precondition: ids overlap');
        $this->age(30);

        app(SandboxCleanup::class)->run(14);

        $this->assertTrue(DeveloperEvent::where('environment', 'production')->where('order_id', $sandboxOrderId)->exists());
        $this->assertSame(1, Order::count());
    }

    public function test_cleanup_is_scheduled_daily_and_logs_safely(): void
    {
        Artisan::call('schedule:list');
        $this->assertStringContainsString('developer-sandbox:prune', Artisan::output());

        $this->populated();
        $this->age(30);
        Log::spy();
        app(SandboxCleanup::class)->run(14);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'Developer sandbox cleanup' && ! str_contains(json_encode($context), 'xt_test_') && ! str_contains(json_encode($context), 'whsec_');
        })->once();
    }
}
