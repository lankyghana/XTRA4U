<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Http\Controllers\VendorDashboardController;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Tools\ReadOnlyGuard;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Nothing that existed before M5.5 moved, and the vendor assistant code has no capability beyond the brief.
 */
class RegressionAndArchitectureTest extends VendorAssistantTestCase
{
    // ------------------------------------------------------------------------------------------ regression

    public function test_no_provider_sdk_or_live_adapter_was_added(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        foreach (array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? [])) as $package) {
            $this->assertDoesNotMatchRegularExpression('#(openai|anthropic|gemini|google/generative|mistral|cohere|langchain)#i', $package, "AI SDK added: {$package}");
        }

        foreach (glob(app_path('Services/Ai/Providers/*.php')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/(OpenAi|Anthropic|Gemini)/i', basename($file), 'live adapter added: '.basename($file));
        }
        $this->assertSame('mock', config('ai.provider'));
    }

    public function test_the_shipped_defaults_keep_the_engine_and_every_surface_off(): void
    {
        $defaults = require config_path('ai.php');

        $this->assertFalse($defaults['enabled']);
        $this->assertSame('mock', $defaults['provider']);
        foreach (['customer', 'vendor', 'developer'] as $surface) {
            $this->assertFalse($defaults['surfaces'][$surface]['enabled'], "{$surface} must ship disabled");
        }
        $this->assertSame(DeveloperAssistant::TOOLS, $defaults['tools']['allowlist']['developer']);
        $this->assertSame(VendorAssistant::TOOLS, $defaults['tools']['allowlist']['vendor']);
        $this->assertSame([], $defaults['tools']['allowlist']['customer']);
    }

    public function test_the_registry_holds_exactly_the_developer_and_vendor_tools_and_no_customer_tool(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->isSealed());
        $this->assertEqualsCanonicalizing(array_merge(DeveloperAssistant::TOOLS, VendorAssistant::TOOLS), $registry->names());
        foreach (DeveloperAssistant::TOOLS as $name) {
            $this->assertSame([AiSurface::Developer], $registry->get($name)->surfaces());
        }
        foreach (VendorAssistant::TOOLS as $name) {
            $this->assertSame([AiSurface::Vendor], $registry->get($name)->surfaces());
        }
        $this->assertSame([], $registry->definitionsFor(AiSurface::Customer));
    }

    public function test_the_six_vendor_tools_are_exactly_the_documented_set(): void
    {
        $this->assertSame([
            'vendor_get_dashboard_summary', 'vendor_search_my_orders', 'vendor_get_my_order',
            'vendor_list_my_products', 'vendor_get_earnings_summary', 'vendor_get_my_store_info',
        ], VendorAssistant::TOOLS);

        foreach (VendorAssistant::TOOLS as $name) {
            $this->assertDoesNotMatchRegularExpression('/(create|update|delete|cancel|refund|pay|withdraw|fulfil|set_|activate|deactivate|retry|send|approve|reset|rotate|revoke)/i', $name, "{$name} sounds like a mutation");
            $this->assertStringStartsWith('vendor_', $name);
        }
    }

    public function test_every_tool_runs_only_read_only_sql(): void
    {
        $w = $this->world();
        $order = $this->makeOrder(['vendor_id' => $w['a']->id]);
        $context = $this->vendorContext($w['a']);
        $statements = [];

        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        foreach (VendorAssistant::TOOLS as $i => $name) {
            $args = match ($name) {
                'vendor_get_my_order' => ['order_ref' => $order->id],
                'vendor_get_earnings_summary' => ['period' => 'all_time'],
                default => [],
            };
            $this->assertTrue($this->runTool($name, $args, $context, 'c'.$i)->isOk(), $name);
        }

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            // Facade/transaction plumbing the engine itself issues is not a tool statement; only tool SQL is checked.
            $this->assertTrue(ReadOnlyGuard::isReadOnlySql($sql), 'non-read statement during tools: '.$sql);
        }
    }

    public function test_other_assistants_and_the_public_api_are_untouched(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertDoesNotMatchRegularExpression('/(assistant|\bai\b|chat|llm)/i', $route->uri(), 'the public API must not expose AI: '.$route->uri());
            }
        }

        $this->postJson('/api/v1/vendor-assistant', ['message' => 'hi'])->assertStatus(404);

        // The Developer Assistant keeps working and keeps its own tools only.
        $vendor = $this->approvedVendor();
        config(['ai.surfaces.developer.enabled' => true]);
        $this->mockProvider()->respondWith('developer answer');
        $this->signedIn($vendor)->postJson(route('developers.assistant.message'), ['message' => 'hello'])->assertOk()->assertJsonPath('answer', 'developer answer');
        $offered = array_column($this->mockProvider()->calls()[0]->tools, 'name');
        $this->assertSame(DeveloperAssistant::TOOLS, $offered);
    }

    public function test_existing_vendor_pages_still_render_and_keep_the_vendor_middleware(): void
    {
        $vendor = $this->approvedVendor();

        foreach (['vendor.dashboard', 'vendor.orders', 'vendor.analytics'] as $name) {
            if (Route::has($name)) {
                $this->signedIn($vendor)->get(route($name))->assertOk();
            }
        }

        foreach (['vendor.assistant.show', 'vendor.assistant.message'] as $name) {
            $this->assertContains('vendor.approved', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    // -------------------------------------------------------------------------------------- architecture

    /** @return list<string> */
    private function assistantFiles(): array
    {
        $files = [];
        foreach ([app_path('Services/Ai/VendorAssistant'), app_path('Services/Vendor')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        $files[] = app_path('Http/Controllers/Vendor/AssistantController.php');
        sort($files);

        return $files;
    }

    /** Code tokens only: comments and string contents never count. @return list<\PhpToken> */
    private function codeTokens(string $path): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize((string) file_get_contents($path)),
            fn (\PhpToken $t) => ! $t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]),
        ));
    }

    public function test_the_assistant_code_has_no_write_payment_wallet_network_or_filesystem_capability(): void
    {
        $forbiddenNames = [
            'Payment', 'PaymentService', 'Wallet', 'WalletService', 'Commission', 'Fulfillment', 'Payout', 'Withdrawal', 'Webhook', 'Setting',
            'Http', 'Mail', 'Notification', 'Storage', 'Queue', 'Bus', 'Event', 'Artisan', 'Process', 'Session', 'Cookie', 'Cache', 'Gate', 'Schema',
            'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
            'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'eval', 'unserialize',
            'dispatch', 'dispatch_sync', 'event', 'Crypt', 'decrypt',
        ];
        $forbiddenMethods = [
            'save', 'saveQuietly', 'update', 'updateOrCreate', 'create', 'firstOrCreate', 'insert', 'insertGetId', 'upsert', 'delete', 'forceDelete',
            'destroy', 'truncate', 'increment', 'decrement', 'forceFill', 'fill', 'touch', 'send', 'post', 'put', 'patch', 'dispatch', 'restore',
            'raw', 'unprepared', 'statement',
        ];

        $violations = [];
        foreach ($this->assistantFiles() as $file) {
            $tokens = $this->codeTokens($file);
            $base = basename($file);
            foreach ($tokens as $i => $t) {
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;

                if ($t->is(T_STRING) && in_array($t->text, $forbiddenNames, true)) {
                    $isMemberOrDeclaration = $prev && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]);
                    $isCallOrStatic = $next && ($next->text === '(' || $next->is(T_DOUBLE_COLON));
                    if (! $isMemberOrDeclaration && $isCallOrStatic) {
                        $violations[] = "{$base}: {$t->text}";
                    }
                }
                if ($t->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && preg_match('/(Facades\\\\(Http|Mail|Storage|Queue|Bus|Event|Artisan|Process|Session|Cookie|Cache|Gate|Schema|Crypt)$|Services\\\\(Payment|Wallet|Order|Fulfil|Webhook|ExternalFulfillment|Payouts)|Jobs\\\\|Events\\\\|Listeners\\\\|Mail\\\\|Notifications\\\\)/', $t->text)) {
                    $violations[] = "{$base}: {$t->text}";
                }
                if ($t->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $next && $next->is(T_STRING) && in_array($next->text, $forbiddenMethods, true) && ($tokens[$i + 2]->text ?? '') === '(') {
                    $violations[] = "{$base}: ->{$next->text}()";
                }
                if ($t->is(T_DOUBLE_COLON) && $next && $next->is(T_STRING) && in_array($next->text, ['create', 'insert', 'unguarded', 'updateOrCreate', 'firstOrCreate', 'destroy', 'truncate'], true) && ($tokens[$i + 2]->text ?? '') === '(') {
                    $violations[] = "{$base}: ::{$next->text}()";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)), 'forbidden capability in vendor assistant code');
    }

    public function test_the_tools_contain_no_raw_sql_and_use_no_string_built_queries(): void
    {
        foreach (glob(app_path('Services/Ai/VendorAssistant/Tools/*.php')) as $file) {
            $source = implode(' ', array_map(fn ($t) => $t->text, $this->codeTokens($file)));
            $this->assertDoesNotMatchRegularExpression('/(DB ::|selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw|DB :: raw|->select\\(\\s*\'\\*\')/', $source, basename($file).' builds raw SQL');
        }

        // The read service keeps the dashboard's own aggregate expressions (moved verbatim) and nothing that writes.
        $service = (string) file_get_contents(app_path('Services/Vendor/VendorDashboardReadService.php'));
        $this->assertDoesNotMatchRegularExpression('/(->(save|update|delete|insert|create|increment|decrement)\\(|DB::(statement|unprepared|insert|update|delete))/', $service);
    }

    public function test_the_tools_never_select_the_sensitive_columns(): void
    {
        $orderFields = (string) file_get_contents(app_path('Services/Ai/VendorAssistant/Tools/OrderFields.php'));
        foreach (['recipient_phone_number', 'mobile_money_number', 'payment_reference', 'gateway', 'delivered_pins', 'provider', 'external_reference', 'customer_email'] as $column) {
            // may be mentioned in a comment explaining the exclusion, but never in an allow-listed select entry
            $this->assertDoesNotMatchRegularExpression("/'(orders\\.)?{$column}[a-z_]*',/", $orderFields, "{$column} is selected");
        }

        $store = (string) file_get_contents(app_path('Services/Ai/VendorAssistant/Tools/GetMyStoreInfoTool.php'));
        $this->assertDoesNotMatchRegularExpression("/'(email|password|remember_token|wallet_balance|balance|phone_number|momo[a-z_]*)'/", $store);
    }

    public function test_the_assistant_reaches_the_model_only_through_the_orchestrator_in_at_most_two_rounds(): void
    {
        foreach ($this->assistantFiles() as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/(MockAiProvider|->complete\(|->chat\(|->generate\(|->stream\()/', $source, basename($file).' talks to a provider directly');

            preg_match_all('/->provider\(\)(->\w+)?/', $source, $uses);
            foreach ($uses[0] as $use) {
                $this->assertSame('->provider()->assertConfigured', $use, basename($file).' uses the provider for more than a configuration check');
            }
        }

        $source = (string) file_get_contents(app_path('Services/Ai/VendorAssistant/VendorAssistant.php'));
        $this->assertSame(2, substr_count($source, '$this->orchestrator->run('), 'one call per round, two rounds at most');
        $this->assertDoesNotMatchRegularExpression('/\b(while|for|foreach)\b[^{;]*\$this->orchestrator/', $source, 'no loop around the orchestrator');
    }

    public function test_the_controller_never_reads_identity_or_tool_choice_from_the_request(): void
    {
        $text = implode(' ', array_map(fn ($t) => $t->text, $this->codeTokens(app_path('Http/Controllers/Vendor/AssistantController.php'))));

        $this->assertStringNotContainsString('->input(', $text);
        $this->assertStringNotContainsString('->get(', $text);
        $this->assertStringNotContainsString('->all() [', $text);
        $this->assertStringNotContainsString('vendor_id', $text);
        $this->assertStringContainsString('ToolContext :: forVendor', $text);
        $this->assertStringContainsString('Auth :: guard (', $text);
        $this->assertStringContainsString("guard('vendor')", (string) file_get_contents(app_path('Http/Controllers/Vendor/AssistantController.php')));
    }

    public function test_the_dashboard_controller_consumes_the_read_service_and_kept_its_public_surface(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/VendorDashboardController.php'));

        $this->assertStringContainsString('VendorDashboardReadService', $source);
        foreach (['getAfaFilteredStats', 'getResultCheckerFilteredStats', 'resolveDateRange'] as $moved) {
            $this->assertStringNotContainsString("function {$moved}", $source, "{$moved} should now live in the read service only");
        }
        foreach (['index', 'orders', 'analytics', 'myResellerProducts'] as $action) {
            $this->assertTrue(method_exists(VendorDashboardController::class, $action), "{$action} must still exist");
        }
    }
}
