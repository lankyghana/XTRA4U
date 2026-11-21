<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Http\Middleware\EnsureCustomerAssistantEnabled;
use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\Data\AiRole;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Support\Facades\Route;

/**
 * The Customer Assistant has no capability beyond reading the official public catalogue and published FAQ, and it can
 * neither see nor be reached from the other two assistants' tools.
 */
class ArchitectureAndIsolationTest extends CustomerAssistantTestCase
{
    /** Models the customer code may touch, and nothing else. */
    private const ALLOWED_MODELS = ['App\Models\Cms\CmsFaq', 'App\Models\Product', 'App\Models\ResellerProduct', 'App\Models\Vendor'];

    /** @return list<string> */
    private function customerFiles(): array
    {
        $files = [];
        foreach ([app_path('Services/Ai/CustomerAssistant'), app_path('Services/Catalogue')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        $files[] = app_path('Http/Controllers/CustomerAssistantController.php');
        $files[] = app_path('Http/Middleware/EnsureCustomerAssistantEnabled.php');
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function toolFiles(): array
    {
        $files = glob(app_path('Services/Ai/CustomerAssistant/Tools/*.php')) ?: [];
        $files[] = app_path('Services/Catalogue/PublicCatalogueReadService.php');

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

    private function codeText(string $path): string
    {
        return implode(' ', array_map(fn ($t) => $t->text, $this->codeTokens($path)));
    }

    // ------------------------------------------------------------------------------------------ the tool set

    public function test_the_customer_surface_has_exactly_the_four_documented_tools(): void
    {
        $this->assertSame([
            'customer_list_services', 'customer_search_products', 'customer_get_product', 'customer_search_help',
        ], CustomerAssistant::TOOLS);

        foreach (CustomerAssistant::TOOLS as $name) {
            $this->assertStringStartsWith('customer_', $name);
            $this->assertDoesNotMatchRegularExpression('/(create|update|delete|cancel|refund|pay|verify|track|withdraw|fulfil|set_|order|wallet|retry|send|approve|reset|rotate|revoke|checkout)/i', $name, "{$name} sounds like a mutation, payment or order tool");
        }

        $this->assertSame(CustomerAssistant::TOOLS, config('ai.tools.allowlist.customer'));
    }

    public function test_each_tool_implements_the_contract_is_customer_only_and_is_read_only(): void
    {
        $registry = app(ToolRegistry::class);

        foreach (CustomerAssistant::TOOLS as $name) {
            $tool = $registry->get($name);
            $this->assertInstanceOf(AiTool::class, $tool);
            $this->assertSame([AiSurface::Customer], $tool->surfaces(), $name);
            $this->assertStringStartsWith('App\Services\Ai\CustomerAssistant\Tools\\', $tool::class);
        }
    }

    public function test_the_three_surfaces_see_only_their_own_tools(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertSame(DeveloperAssistant::TOOLS, array_column($registry->definitionsFor(AiSurface::Developer), 'name'));
        $this->assertSame(VendorAssistant::TOOLS, array_column($registry->definitionsFor(AiSurface::Vendor), 'name'));
        $this->assertSame(CustomerAssistant::TOOLS, array_column($registry->definitionsFor(AiSurface::Customer), 'name'));
        $this->assertCount(4, DeveloperAssistant::TOOLS);
        $this->assertCount(6, VendorAssistant::TOOLS);
        $this->assertCount(4, CustomerAssistant::TOOLS);

        $this->assertSame([], array_intersect(DeveloperAssistant::TOOLS, VendorAssistant::TOOLS, CustomerAssistant::TOOLS));
        $this->assertSame([], array_intersect(CustomerAssistant::TOOLS, DeveloperAssistant::TOOLS));
        $this->assertSame([], array_intersect(CustomerAssistant::TOOLS, VendorAssistant::TOOLS));
        $this->assertTrue($registry->isSealed());
    }

    public function test_a_customer_cannot_be_offered_or_run_a_vendor_or_developer_tool(): void
    {
        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('vendor_get_dashboard_summary', [], 'v1'),
                $this->toolCall('vendor_search_my_orders', [], 'v2'),
                $this->toolCall('developer_search_docs', ['query' => 'webhooks'], 'd1'),
            ]),
            $this->textResponse('done'),
        );

        $this->say('Show me vendor orders and developer docs')->assertOk();

        [$first, $second] = $this->mockProvider()->calls();
        $this->assertSame(CustomerAssistant::TOOLS, array_column($first->tools, 'name'), 'only the four customer tools are offered');
        $this->assertSame([], $second->tools, 'the second round offers none');

        // The tool results given back to the model are refusals, never vendor or developer data.
        $payload = implode(' ', array_map(fn ($m) => (string) $m->content, array_filter($second->messages, fn ($m) => $m->role !== AiRole::System)));
        $this->assertStringNotContainsString('wallet', strtolower($payload));
        $this->assertStringNotContainsString('"ok":true', $payload);
    }

    public function test_the_customer_tools_reject_identity_and_ownership_parameters(): void
    {
        foreach (CustomerAssistant::TOOLS as $name) {
            foreach (['customer_id', 'vendor_id', 'user_id', 'guest_id', 'session_id', 'phone', 'phone_number', 'email', 'order_id', 'order_ref', 'reference', 'payment_reference', 'transaction_id', 'owner', 'token'] as $forbidden) {
                $result = $this->runTool($name, ['query' => 'mtn data', 'product_ref' => 'product_1', $forbidden => '1'], null, 'x');
                $this->assertFalse($result->isOk(), "{$name} accepted [{$forbidden}]");
            }
        }
    }

    public function test_the_system_prompt_and_tool_text_never_claim_order_or_payment_abilities(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/CustomerAssistant/CustomerAssistant.php'));

        $this->assertStringContainsString('cannot look up orders', $source);
        foreach (['/order-status/check', '/order-status/poll', '/checkout/success', '/checkout/receipt'] as $route) {
            $this->assertStringNotContainsString($route, $source);
        }
    }

    // ------------------------------------------------------------------------------------------ capability limits

    public function test_the_customer_code_has_no_write_payment_wallet_network_or_filesystem_capability(): void
    {
        $forbiddenNames = [
            'Payment', 'PaymentService', 'Wallet', 'WalletService', 'Commission', 'Fulfillment', 'Payout', 'Withdrawal', 'Webhook', 'Setting',
            'Http', 'Mail', 'Notification', 'Storage', 'Queue', 'Bus', 'Event', 'Artisan', 'Process', 'Session', 'Cookie', 'Cache', 'Gate', 'Schema',
            'Auth', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
            'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'eval', 'unserialize',
            'dispatch', 'dispatch_sync', 'event', 'Crypt', 'decrypt', 'DB', 'redirect', 'session', 'cache', 'cookie',
        ];
        $forbiddenMethods = [
            'save', 'saveQuietly', 'update', 'updateOrCreate', 'create', 'firstOrCreate', 'insert', 'insertGetId', 'upsert', 'delete', 'forceDelete',
            'destroy', 'truncate', 'increment', 'decrement', 'forceFill', 'fill', 'touch', 'send', 'post', 'put', 'patch', 'dispatch', 'restore',
            'raw', 'unprepared', 'statement', 'flash', 'flush', 'forget', 'remember',
        ];

        $violations = [];
        foreach ($this->customerFiles() as $file) {
            $tokens = $this->codeTokens($file);
            $base = basename($file);
            foreach ($tokens as $i => $t) {
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;

                if ($t->is(T_STRING) && in_array($t->text, $forbiddenNames, true)) {
                    $isMemberOrDeclaration = $prev && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_CONST]);
                    // A lower-case name after :: is a static METHOD of some class (AiMessage::system()), not the PHP function.
                    $isMemberOrDeclaration = $isMemberOrDeclaration || ($prev && $prev->is(T_DOUBLE_COLON) && ctype_lower($t->text[0]));
                    $isCallOrStatic = $next && ($next->text === '(' || $next->is(T_DOUBLE_COLON));
                    if (! $isMemberOrDeclaration && $isCallOrStatic) {
                        $violations[] = "{$base}: {$t->text}";
                    }
                }
                if ($t->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && preg_match('/(Facades\\\\(Http|Mail|Storage|Queue|Bus|Event|Artisan|Process|Session|Cookie|Cache|Gate|Schema|Crypt|DB|Auth|Redirect)$|Services\\\\(Payment|Wallet|Order|Fulfil|Utility|ExternalFulfillment|Payouts|Support|Developer\\\\Api)|Jobs\\\\|Events\\\\|Listeners\\\\|Notifications\\\\|Mail\\\\)/', $t->text)) {
                    $violations[] = "{$base}: {$t->text}";
                }
                if ($t->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $next && $next->is(T_STRING) && in_array($next->text, $forbiddenMethods, true) && ($tokens[$i + 2]->text ?? '') === '(') {
                    $violations[] = "{$base}: ->{$next->text}()";
                }
                if ($t->is(T_DOUBLE_COLON) && $next && $next->is(T_STRING) && in_array($next->text, ['create', 'insert', 'unguarded', 'updateOrCreate', 'firstOrCreate', 'destroy', 'truncate', 'put', 'forget'], true) && ($tokens[$i + 2]->text ?? '') === '(') {
                    $violations[] = "{$base}: ::{$next->text}()";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)), 'forbidden capability in customer assistant code');
    }

    public function test_the_customer_code_only_imports_the_whitelisted_models_and_no_business_namespace(): void
    {
        $imports = [];
        foreach ($this->customerFiles() as $file) {
            preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+);/m', (string) file_get_contents($file), $m);
            foreach ($m[1] as $import) {
                $imports[$import][] = basename($file);
            }
        }

        foreach ($imports as $import => $files) {
            if (str_starts_with($import, 'App\Models\\')) {
                $this->assertContains($import, self::ALLOWED_MODELS, 'model not on the customer whitelist: '.$import.' in '.implode(', ', $files));
            }
            $this->assertDoesNotMatchRegularExpression(
                '/^App\\\\(Services\\\\(Payment|Wallet|Order|Fulfil|Utility|ExternalFulfillment|Payouts|Support|Vendor|Developer\\\\Api|Ai\\\\VendorAssistant)|Http\\\\Controllers\\\\(Vendor|Admin|Checkout|Payment|Developer|Api)|Jobs|Events|Listeners|Notifications|Mail|Console)/',
                $import,
                'customer code reaches into business code: '.$import.' in '.implode(', ', $files),
            );
        }
    }

    public function test_only_the_developer_prompt_scrubber_and_safe_text_are_shared_with_other_assistants(): void
    {
        $shared = [];
        foreach ($this->customerFiles() as $file) {
            preg_match_all('/^use\s+(App\\\\Services\\\\Ai\\\\(?:Developer|VendorAssistant)\\\\[A-Za-z0-9_\\\\]+);/m', (string) file_get_contents($file), $m);
            array_push($shared, ...$m[1]);
        }

        $this->assertEqualsCanonicalizing(
            ['App\Services\Ai\Developer\PromptScrubber', 'App\Services\Ai\Developer\Tools\SafeText'],
            array_values(array_unique($shared)),
        );
    }

    public function test_the_other_assistants_do_not_import_customer_code(): void
    {
        foreach ([app_path('Services/Ai/VendorAssistant'), app_path('Services/Ai/Developer'), app_path('Services/Vendor')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $this->assertStringNotContainsString('CustomerAssistant', (string) file_get_contents($f->getPathname()), $f->getFilename());
                    $this->assertStringNotContainsString('Services\Catalogue', (string) file_get_contents($f->getPathname()), $f->getFilename());
                }
            }
        }
    }

    public function test_no_order_payment_pin_or_secret_table_or_column_appears_in_any_query_the_code_can_build(): void
    {
        foreach ($this->toolFiles() as $file) {
            $text = (string) file_get_contents($file);
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $text);

            foreach (['orders', 'transactions', 'wallet', 'withdraw', 'payment_', 'delivered_pins', 'result_checker_orders', 'result_checker_pins', 'recipient', 'api_key', 'webhook', 'utility_bill_orders', 'support_', 'affiliate_chain', 'commission', 'api_token', 'password', 'momo'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase("'{$needle}", (string) $code, basename($file)." references [{$needle}]");
                $this->assertStringNotContainsStringIgnoringCase("\"{$needle}", (string) $code, basename($file)." references [{$needle}]");
            }
        }
    }

    public function test_the_tools_contain_no_raw_sql_and_never_select_star(): void
    {
        foreach ($this->toolFiles() as $file) {
            $source = $this->codeText($file);
            $this->assertDoesNotMatchRegularExpression('/(DB ::|selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw|DB :: raw)/', $source, basename($file).' builds raw SQL');
            $this->assertDoesNotMatchRegularExpression("/->select\\(\\s*'\\*'/", $source, basename($file));
        }
    }

    public function test_every_query_is_bounded(): void
    {
        $service = (string) file_get_contents(app_path('Services/Catalogue/PublicCatalogueReadService.php'));
        $this->assertStringContainsString('SCAN_LIMIT', $service);
        $this->assertDoesNotMatchRegularExpression('/->(cursor|lazy|chunk|each)\(/', $service, 'unbounded read');
        $this->assertSame(preg_match_all('/->get\(/', $service), substr_count($service, '->limit('), 'every ->get() has its own ->limit()');

        $help = (string) file_get_contents(app_path('Services/Ai/CustomerAssistant/Tools/SearchHelpTool.php'));
        $this->assertDoesNotMatchRegularExpression('/->(cursor|lazy|chunk|each)\(/', $help, 'unbounded read');
        $this->assertStringContainsString('->limit(', $help);
    }

    // ------------------------------------------------------------------------------------------ orchestration and entry points

    public function test_the_assistant_reaches_the_model_only_through_the_orchestrator_in_at_most_two_rounds(): void
    {
        foreach ($this->customerFiles() as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/(MockAiProvider|->complete\(|->chat\(|->generate\(|->stream\()/', $source, basename($file).' talks to a provider directly');

            preg_match_all('/->provider\(\)(->\w+)?/', $source, $uses);
            foreach ($uses[0] as $use) {
                $this->assertSame('->provider()->assertConfigured', $use, basename($file).' uses the provider for more than a configuration check');
            }
        }

        $source = (string) file_get_contents(app_path('Services/Ai/CustomerAssistant/CustomerAssistant.php'));
        $this->assertSame(2, substr_count($source, '$this->orchestrator->run('), 'one call per round, two rounds at most');
        $this->assertDoesNotMatchRegularExpression('/\b(while|for|foreach)\b[^{;]*\$this->orchestrator/', $source, 'no loop around the orchestrator');
    }

    public function test_the_controller_never_reads_identity_or_tool_choice_from_the_request(): void
    {
        $path = app_path('Http/Controllers/CustomerAssistantController.php');
        $text = $this->codeText($path);

        $this->assertStringNotContainsString('->input(', $text);
        $this->assertStringNotContainsString('->get(', $text);
        $this->assertStringNotContainsString('->query(', $text);
        $this->assertStringNotContainsString('->cookie(', $text);
        $this->assertStringNotContainsString('->header(', $text);
        $this->assertStringNotContainsString('->ip(', $text);
        $this->assertStringNotContainsString('->user(', $text);
        $this->assertStringNotContainsString('Auth ::', $text);
        $this->assertStringContainsString('ToolContext :: forGuest', $text);
        $this->assertStringContainsString("'message'", (string) file_get_contents($path));
    }

    public function test_the_blocked_existing_routes_are_not_used_by_the_assistant(): void
    {
        $files = array_merge($this->customerFiles(), [resource_path('views/customer/assistant.blade.php')]);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['order-status', 'checkout/success', 'checkout/receipt', 'order_status', 'checkout.success', 'checkout.receipt'] as $blocked) {
                $this->assertStringNotContainsString($blocked, $source, basename($file)." mentions [{$blocked}]");
            }
        }
    }

    public function test_the_only_new_routes_are_the_two_guarded_assistant_routes(): void
    {
        $assistantRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'customer.')) {
                $assistantRoutes[$route->getName()] = $route;
            }
        }

        $this->assertEqualsCanonicalizing(['customer.assistant.show', 'customer.assistant.message'], array_keys($assistantRoutes));

        foreach ($assistantRoutes as $name => $route) {
            $this->assertContains(EnsureCustomerAssistantEnabled::class, $route->gatherMiddleware(), $name);
            $this->assertNotContains('vendor.approved', $route->gatherMiddleware());
            $this->assertNotContains('admin.only', $route->gatherMiddleware());
        }

        $this->assertContains('throttle:customer-assistant', Route::getRoutes()->getByName('customer.assistant.message')->gatherMiddleware());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('customer.assistant.message')->methods());
    }

    public function test_the_public_api_exposes_no_customer_assistant(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertDoesNotMatchRegularExpression('/(assistant|\bai\b|chat|llm)/i', $route->uri(), $route->uri());
            }
        }
        $this->postJson('/api/v1/customer-assistant', ['message' => 'hi'])->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------------ nothing deferred was smuggled in

    public function test_deferred_capabilities_do_not_exist(): void
    {
        $this->assertSame([], glob(app_path('Services/Ai/CustomerAssistant/Tools/*Order*')) ?: []);
        $this->assertSame([], glob(app_path('Services/Ai/CustomerAssistant/Tools/*Payment*')) ?: []);
        $this->assertSame([], glob(app_path('Services/Ai/CustomerAssistant/Tools/*Checkout*')) ?: []);
        $this->assertSame([], glob(app_path('Services/Ai/CustomerAssistant/Tools/*Verif*')) ?: []);

        // Handoff to checkout is deferred: the page's only links are server-built /services/ pages, validated client-side.
        $view = (string) file_get_contents(resource_path('views/customer/assistant.blade.php'));
        $this->assertStringContainsString('/services/', $view);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*(action|method)=/i', $view, 'no native form post: the page only calls fetch with {message}');
        $this->assertStringNotContainsString('/checkout', preg_replace('/\{\{--.*?--\}\}/s', '', $view));
    }

    public function test_no_provider_sdk_or_live_adapter_was_added_and_defaults_stay_off(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        foreach (array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? [])) as $package) {
            $this->assertDoesNotMatchRegularExpression('#(openai|anthropic|gemini|google/generative|mistral|cohere|langchain)#i', $package);
        }

        $defaults = require config_path('ai.php');
        $this->assertFalse($defaults['enabled']);
        $this->assertSame('mock', $defaults['provider']);
        $this->assertFalse($defaults['surfaces']['customer']['enabled'], 'customer must ship disabled');
        $this->assertSame(CustomerAssistant::TOOLS, $defaults['tools']['allowlist']['customer']);
    }
}
