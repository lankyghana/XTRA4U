<?php

namespace Tests\Feature\Ai\Tools;

use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\DeveloperApi\ApiKeyService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Structural guarantees for the tool infrastructure (comments are ignored: only real code tokens count).
 * Read-only is a property of the architecture, not of good intentions:
 *  - nothing under App\Services\Ai\Tools may depend on payment, wallet, commission, fulfillment, PIN, inventory,
 *    API-key, webhook, order, messaging or gateway code;
 *  - it may not write (no DB write calls, no model persistence, no files, no HTTP, no shell, no eval);
 *  - M5.3 ships no business tool, no route, no migration, no controller.
 */
class ToolArchitectureTest extends TestCase
{
    private const FORBIDDEN_NAME_PARTS = [
        'Payment', 'Paystack', 'Flutterwave', 'Hubtel', 'BulkClix', 'Moolre', 'Gateway', 'Wallet', 'Commission', 'Affiliate',
        'Fulfillment', 'Fulfilment', 'ResultChecker', 'Pin', 'Inventory', 'Payout', 'Withdraw', 'ApiKey', 'Webhook',
        'Order', 'Transaction', 'Sms', 'Mail', 'Notification', 'Checkout', 'Refund', 'Ledger',
    ];

    /** Function names that can write, execute or reach the network/filesystem. */
    private const FORBIDDEN_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'eval', 'assert', 'file_put_contents', 'fwrite', 'fputs',
        'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client',
        'file_get_contents', 'fopen', 'include', 'require', 'include_once', 'require_once', 'unserialize', 'call_user_func',
        'call_user_func_array', 'create_function', 'mail',
    ];

    /** Facades/classes whose use means I/O or side effects. */
    private const FORBIDDEN_CLASSES = ['Http', 'Storage', 'File', 'Queue', 'Event', 'Bus', 'Notification', 'Process', 'Artisan', 'Schema', 'Redis', 'Session'];

    /** Methods that persist or mutate, wherever they are called. */
    private const FORBIDDEN_METHODS = [
        'save', 'saveQuietly', 'create', 'firstOrCreate', 'updateOrCreate', 'insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'update',
        'delete', 'forceDelete', 'destroy', 'truncate', 'increment', 'decrement', 'statement', 'unprepared', 'affectingStatement',
        'raw', 'selectRaw', 'whereRaw', 'orderByRaw', 'havingRaw', 'dispatch', 'dispatchSync', 'push', 'later', 'send', 'put', 'forever', 'forget',
    ];

    /** @return list<string> */
    private function toolFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Services/Ai/Tools')));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<array{0:int,1:string,2:int}> */
    private function code(string $file): array
    {
        $out = [];
        foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $t) {
            if (! in_array($t->id, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                $out[] = [$t->id, $t->text, $t->line];
            }
        }

        return $out;
    }

    public function test_the_tool_files_exist(): void
    {
        $this->assertGreaterThan(15, count($this->toolFiles()));
    }

    public function test_the_tool_infrastructure_depends_on_no_money_fulfillment_or_credential_code(): void
    {
        $hits = [];

        foreach ($this->toolFiles() as $file) {
            foreach ($this->code($file) as [$id, $text, $line]) {
                if (! in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    continue;
                }
                foreach (explode('\\', $text) as $part) {
                    foreach (self::FORBIDDEN_NAME_PARTS as $bad) {
                        // whole-word-ish: "Pin" must not match "Mapping"; the others are specific enough to match anywhere
                        $pattern = $bad === 'Pin' ? '/^(Pin|Pins|Pin[A-Z]\w*)$/' : '/'.preg_quote($bad, '/').'/i';
                        if (preg_match($pattern, $part) === 1) {
                            $hits[] = basename($file).":{$line} {$text}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($hits)), 'the tool layer must not touch payment/wallet/fulfillment/credential code');
    }

    public function test_the_tool_infrastructure_cannot_write_or_reach_outside_the_process(): void
    {
        $hits = [];

        foreach ($this->toolFiles() as $file) {
            $tokens = $this->code($file);

            foreach ($tokens as $i => [$id, $text, $line]) {
                $next = $tokens[$i + 1][1] ?? '';
                $prev = $tokens[$i - 1][1] ?? '';

                // function calls: name followed by "(" and not a method/static member of something else
                if ($id === T_STRING && $next === '(' && ! in_array($prev, ['->', '::', 'function'], true) && in_array(strtolower($text), self::FORBIDDEN_FUNCTIONS, true)) {
                    $hits[] = basename($file).":{$line} {$text}()";
                }
                if (in_array($id, [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT], true)) {
                    $hits[] = basename($file).":{$line} {$text}";
                }

                // Facade::method
                if ($id === T_STRING && $next === '::' && in_array($text, self::FORBIDDEN_CLASSES, true)) {
                    $hits[] = basename($file).":{$line} {$text}::";
                }

                // mutating method calls: ->save( ::create( DB::insert( ...
                if ($id === T_STRING && $next === '(' && in_array($prev, ['->', '::'], true) && in_array($text, self::FORBIDDEN_METHODS, true)) {
                    $hits[] = basename($file).":{$line} {$prev}{$text}()";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($hits)));
    }

    public function test_only_the_guard_may_touch_the_database_connection_and_only_to_observe_it(): void
    {
        foreach ($this->toolFiles() as $file) {
            $uses = array_filter($this->code($file), fn ($t) => $t[1] === 'DB' || $t[1] === 'Illuminate\\Support\\Facades\\DB');
            if ($uses !== []) {
                $this->assertSame('ReadOnlyGuard.php', basename($file), basename($file).' must not use the DB facade');
                $src = (string) file_get_contents($file);
                $this->assertSame(1, preg_match_all('/DB::\w+\(/', $src, $m));
                $this->assertSame(['DB::connection('], $m[0]);
            }
        }
    }

    public function test_the_contract_has_no_write_capable_sibling(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(AiTool::class))->getMethods());
        sort($methods);
        $this->assertSame(['authorize', 'description', 'handle', 'name', 'schema', 'surfaces'], $methods);

        $contracts = array_map('basename', glob(app_path('Services/Ai/Tools/Contracts/*.php')));
        $this->assertSame(['AiTool.php'], $contracts, 'there is one tool contract and it is read-only; no write-capable variant exists');
    }

    public function test_only_the_developer_and_vendor_assistants_implement_tools(): void
    {
        $implementations = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && preg_match('/implements\s+[^{]*\bAiTool\b/', (string) file_get_contents($f->getPathname()))) {
                $implementations[] = str_replace('\\', '/', substr($f->getPathname(), strlen(app_path()) + 1));
            }
        }
        sort($implementations);

        // M5.3 shipped none. M5.4 adds exactly the four Developer Assistant tools (see DeveloperAssistant/RegressionAndArchitectureTest
        // for their own structural limits); the tool INFRASTRUCTURE directory itself still contains no tool.
        $this->assertSame([
            // M5.6: exactly the four Customer Assistant tools (see CustomerAssistant/ArchitectureTest for their limits).
            'Services/Ai/CustomerAssistant/Tools/GetProductTool.php',
            'Services/Ai/CustomerAssistant/Tools/ListServicesTool.php',
            'Services/Ai/CustomerAssistant/Tools/SearchHelpTool.php',
            'Services/Ai/CustomerAssistant/Tools/SearchProductsTool.php',
            'Services/Ai/Developer/Tools/GetMyAppTool.php',
            'Services/Ai/Developer/Tools/ListMyAppsTool.php',
            'Services/Ai/Developer/Tools/ReadDeveloperDocTool.php',
            'Services/Ai/Developer/Tools/SearchDeveloperDocsTool.php',
            // M5.5: exactly the six Vendor Assistant tools (see VendorAssistant/RegressionAndArchitectureTest for their limits).
            'Services/Ai/VendorAssistant/Tools/GetDashboardSummaryTool.php',
            'Services/Ai/VendorAssistant/Tools/GetEarningsSummaryTool.php',
            'Services/Ai/VendorAssistant/Tools/GetMyOrderTool.php',
            'Services/Ai/VendorAssistant/Tools/GetMyStoreInfoTool.php',
            'Services/Ai/VendorAssistant/Tools/ListMyProductsTool.php',
            'Services/Ai/VendorAssistant/Tools/SearchMyOrdersTool.php',
        ], $implementations);
    }

    public function test_m53_adds_no_routes_migrations_controllers_or_views(): void
    {
        foreach (glob(base_path('routes/*.php')) as $routes) {
            $this->assertStringNotContainsString('Services\\Ai', (string) file_get_contents($routes), basename($routes));
            $this->assertSame(0, preg_match('~[\'"]/?ai[\'"/]~', (string) file_get_contents($routes)), basename($routes).' must not declare AI routes');
        }

        $this->assertSame([], array_values(array_filter(glob(base_path('database/migrations/*_ai_*')), fn ($m) => ! str_contains($m, 'ai_durable_budget') && ! str_contains($m, 'ai_budget_reservations'))), 'no AI migrations other than the M5.7.1 durable budget / kill-switch state and the M5.7.2 anchor sequence column');
        $this->assertSame([], glob(app_path('Http/Controllers/*Ai*')));
        $this->assertSame([], glob(base_path('resources/views/ai*')));
        $this->assertFalse(Route::has('ai.chat'));
    }

    public function test_the_unavailable_ai_chat_scope_is_still_unavailable(): void
    {
        $this->assertSame(['ai:chat'], config('developer_api.unavailable_scopes'));
        $this->assertArrayNotHasKey('ai:chat', ApiKeyService::availableScopes());
    }
}
