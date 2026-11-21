<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\DeveloperApi\ApiKeyService;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Support\Facades\Route;

/**
 * Nothing that existed before M5.4 moved, and the assistant code has no capability beyond the brief.
 */
class RegressionAndArchitectureTest extends DeveloperAssistantTestCase
{
    // ------------------------------------------------------------------------------------------ regression

    public function test_the_ai_chat_scope_is_still_declared_but_unusable(): void
    {
        $this->assertSame(['ai:chat'], config('developer_api.unavailable_scopes'));
        $this->assertArrayHasKey('ai:chat', config('developer_api.scopes'));
        $this->assertArrayNotHasKey('ai:chat', ApiKeyService::availableScopes());

        $app = $this->makeApp($this->approvedVendor());
        $this->expectException(ApiException::class);
        app(ApiKeyService::class)->create($app, 'k', ['services:read', 'ai:chat']);
    }

    public function test_the_scope_list_is_exactly_what_it_was(): void
    {
        $this->assertSame(
            ['services:read', 'products:read', 'quotes:create', 'orders:create', 'orders:read', 'payments:initiate', 'webhooks:manage', 'ai:chat', 'sandbox:simulate'],
            array_keys(config('developer_api.scopes')),
        );
        $this->assertSame(['sandbox:simulate'], config('developer_api.sandbox_only_scopes'));
        $this->assertSame(['data'], config('developer_api.exposed_categories'));
    }

    public function test_no_public_api_route_exposes_the_assistant_or_ai(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertDoesNotMatchRegularExpression('/(assistant|\bai\b|chat|llm)/i', $route->uri(), 'the public API must not expose AI: '.$route->uri());
            }
        }

        $this->getJson('/api/v1/health')->assertOk();
        $this->postJson('/api/v1/assistant', ['message' => 'hi'])->assertStatus(404);
        $this->postJson('/api/v1/ai/chat', ['message' => 'hi'])->assertStatus(404);
    }

    public function test_the_existing_portal_pages_still_work_and_the_nav_link_follows_availability(): void
    {
        $vendor = $this->approvedVendor();

        foreach (['developers.dashboard', 'developers.applications.index'] as $name) {
            if (Route::has($name)) {
                $this->signedIn($vendor)->get(route($name))->assertOk();
            }
        }
        $this->get(route('developers.landing'))->assertOk();

        $this->signedIn($vendor)->get(route('developers.dashboard'))->assertOk()->assertSee(route('developers.assistant'), false);

        // Disabled: the link disappears, the page explains, and posting is refused without touching the provider.
        config(['ai.surfaces.developer.enabled' => false]);
        $this->signedIn($vendor)->get(route('developers.dashboard'))->assertOk()->assertDontSee(route('developers.assistant'), false);
        $this->signedIn($vendor)->get(route('developers.assistant'))->assertOk()->assertSee('data-assistant-state="unavailable"', false);
        $this->ask($vendor, 'hello')->assertStatus(503);
        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_the_developer_access_middleware_is_unchanged_and_still_guards_every_portal_route(): void
    {
        $unguarded = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'developers') && ! in_array($route->getName(), ['developers.landing'], true)) {
                if (! in_array('developer.access', $route->gatherMiddleware(), true)) {
                    $unguarded[] = $route->uri();
                }
            }
        }
        $this->assertSame([], $unguarded);
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
        // M5.5 allow-lists the Vendor Assistant's six tools on `vendor` (inert while its surface is off).
        $this->assertSame(\App\Services\Ai\VendorAssistant\VendorAssistant::TOOLS, $defaults['tools']['allowlist']['vendor']);
        $this->assertSame([], $defaults['tools']['allowlist']['customer']);
    }

    public function test_the_four_documented_developer_tools_exist_and_they_are_developer_only(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->isSealed());
        // M5.5 adds the six vendor tools; the four developer tools are unchanged and still developer-only.
        $this->assertEqualsCanonicalizing(array_merge(DeveloperAssistant::TOOLS, \App\Services\Ai\VendorAssistant\VendorAssistant::TOOLS), $registry->names());
        foreach (DeveloperAssistant::TOOLS as $name) {
            $this->assertSame([\App\Services\Ai\Data\AiSurface::Developer], $registry->get($name)->surfaces());
        }
        $this->assertSame([
            'developer_search_docs', 'developer_read_doc', 'developer_list_my_apps', 'developer_get_my_app',
        ], DeveloperAssistant::TOOLS);
    }

    public function test_no_provider_sdk_or_live_adapter_was_added(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $packages = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));
        foreach ($packages as $package) {
            $this->assertDoesNotMatchRegularExpression('#(openai|anthropic|gemini|google/generative|mistral|cohere|langchain)#i', $package, "AI SDK added: {$package}");
        }

        $providers = glob(app_path('Services/Ai/Providers/*.php')) ?: [];
        $names = array_map('basename', $providers);
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/(OpenAi|Anthropic|Gemini)/i', $name, "live adapter added: {$name}");
        }
        $this->assertSame('mock', config('ai.provider'));
    }

    // -------------------------------------------------------------------------------------- architecture

    /** @return list<string> */
    private function assistantFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Services/Ai/Developer'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        $files[] = app_path('Http/Controllers/Developers/AssistantController.php');
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

    public function test_the_assistant_code_touches_only_the_two_developer_models(): void
    {
        $allowed = [
            'App\\Models\\Developer\\DeveloperApplication',
            'App\\Models\\Developer\\DeveloperApiKey',
        ];
        $seen = [];

        foreach ($this->assistantFiles() as $file) {
            preg_match_all('/^use (App\\\\Models\\\\[^;]+);/m', (string) file_get_contents($file), $m);
            foreach ($m[1] as $model) {
                $seen[] = $model;
                $this->assertContains($model, $allowed, basename($file).' imports a model outside the allowlist: '.$model);
            }
        }

        // And only the two read tools use them at all.
        $this->assertNotEmpty($seen);
    }

    public function test_the_assistant_code_has_no_write_payment_wallet_order_network_or_filesystem_capability(): void
    {
        $forbiddenNames = [
            // business domains
            'Payment', 'PaymentService', 'Wallet', 'WalletService', 'Transaction', 'Order', 'Commission', 'Fulfillment', 'Payout', 'Withdrawal',
            'ResultChecker', 'Pin', 'Webhook', 'WebhookEndpoint', 'WebhookDelivery', 'Setting',
            // side-effect facades and helpers
            'Http', 'Mail', 'Notification', 'Storage', 'Queue', 'Bus', 'Event', 'Artisan', 'Process', 'Session', 'Cookie', 'Cache',
            'Gate', 'Schema',
            // network / process / filesystem writes
            'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
            'file_put_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'eval', 'unserialize',
            'dispatch', 'dispatch_sync', 'event',
        ];
        $forbiddenMethods = [
            'save', 'saveQuietly', 'update', 'updateOrCreate', 'create', 'firstOrCreate', 'insert', 'insertGetId', 'upsert', 'delete', 'forceDelete',
            'destroy', 'truncate', 'increment', 'decrement', 'forceFill', 'fill', 'touch', 'send', 'post', 'put', 'patch', 'dispatch',
        ];

        $violations = [];
        foreach ($this->assistantFiles() as $file) {
            $tokens = $this->codeTokens($file);
            $base = basename($file);
            foreach ($tokens as $i => $t) {
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;

                // identifiers / function calls
                if ($t->is(T_STRING) && in_array($t->text, $forbiddenNames, true)) {
                    $isMemberOrDeclaration = $prev && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NS_SEPARATOR]) && ! $prev->is(T_NS_SEPARATOR);
                    $isCallOrStatic = $next && ($next->text === '(' || $next->is(T_DOUBLE_COLON));
                    if (! $isMemberOrDeclaration && $isCallOrStatic) {
                        $violations[] = "{$base}: {$t->text}";
                    }
                }
                // qualified names in `use` or inline (App\Services\Payments\X, Illuminate\Support\Facades\Http ...)
                if ($t->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && preg_match('/(Facades\\\\(Http|Mail|Storage|Queue|Bus|Event|Artisan|Process|Session|Cookie|Cache|Gate|Schema)$|Services\\\\(Payment|Wallet|Order|Fulfil|Webhook|ExternalFulfillment|Payouts)|Models\\\\(?!Developer\\\\Developer(Application|ApiKey)$)|Jobs\\\\|Events\\\\|Listeners\\\\|Mail\\\\|Notifications\\\\)/', $t->text)) {
                    $violations[] = "{$base}: {$t->text}";
                }
                // mutating or network method calls
                if ($t->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $next && $next->is(T_STRING) && in_array($next->text, $forbiddenMethods, true) && ($tokens[$i + 2]->text ?? '') === '(') {
                    $violations[] = "{$base}: ->{$next->text}()";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)), 'forbidden capability in assistant code');
    }

    public function test_file_reads_exist_only_in_the_document_catalog_and_never_use_request_input(): void
    {
        foreach ($this->assistantFiles() as $file) {
            $tokens = $this->codeTokens($file);
            foreach ($tokens as $t) {
                if ($t->is(T_STRING) && in_array($t->text, ['file_get_contents', 'file', 'readfile', 'fopen', 'glob', 'scandir', 'opendir', 'realpath'], true)) {
                    $this->assertSame('DeveloperDocumentCatalog.php', basename($file), basename($file).' reads the filesystem');
                }
            }
        }

        $catalog = (string) file_get_contents(app_path('Services/Ai/Developer/Docs/DeveloperDocumentCatalog.php'));
        $this->assertStringNotContainsString('request(', $catalog);
        $this->assertStringNotContainsString('$_GET', $catalog);
        $this->assertStringContainsString('realpath', $catalog, 'containment is verified with realpath');
    }

    public function test_the_assistant_reaches_the_model_only_through_the_orchestrator(): void
    {
        foreach ($this->assistantFiles() as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/(MockAiProvider|->complete\(|->chat\(|->generate\(|->stream\()/', $source, basename($file).' talks to a provider directly');

            // The provider manager may be used for exactly one thing: the fail-closed configuration check behind
            // isAvailable(). Any other use of ->provider() would be a way around the orchestrator.
            preg_match_all('/->provider\(\)(->\w+)?/', $source, $uses);
            foreach ($uses[0] as $use) {
                $this->assertSame('->provider()->assertConfigured', $use, basename($file).' uses the provider for more than a configuration check');
            }
        }

        $source = (string) file_get_contents(app_path('Services/Ai/Developer/DeveloperAssistant.php'));
        $this->assertSame(2, substr_count($source, '$this->orchestrator->run('), 'one call per round, two rounds at most');
        $this->assertDoesNotMatchRegularExpression('/\b(while|for|foreach)\b[^{;]*\$this->orchestrator/', $source, 'no loop around the orchestrator');
    }

    public function test_the_controller_never_reads_identity_or_tool_choice_from_the_request(): void
    {
        $tokens = $this->codeTokens(app_path('Http/Controllers/Developers/AssistantController.php'));
        $text = implode(' ', array_map(fn ($t) => $t->text, $tokens));

        $this->assertStringNotContainsString('->input(', $text);
        $this->assertStringNotContainsString('->get(', $text);
        $this->assertStringNotContainsString('->all(', $text);
        $this->assertStringNotContainsString('vendor_id', $text);
        $this->assertStringContainsString('ToolContext :: forDeveloper', $text);
    }
}
