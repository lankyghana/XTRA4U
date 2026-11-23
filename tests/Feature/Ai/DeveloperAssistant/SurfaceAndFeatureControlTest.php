<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiDisabledException;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Tools\Exceptions\AiToolRegistrationException;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Ai\Tools\Support\FakeTool;

/**
 * The developer surface is OFF by default and honours every M5.2 switch; vendor and customer surfaces can never reach
 * the developer tools; a wrong allowlist makes the assistant unavailable instead of unsafe.
 */
class SurfaceAndFeatureControlTest extends DeveloperAssistantTestCase
{
    private function assertUnavailable(string $expectedCode): void
    {
        $vendor = $this->approvedVendor();

        $this->signedIn($vendor)->get(route('developers.assistant'))
            ->assertOk()
            ->assertSee('data-assistant-state="unavailable"', false)
            ->assertDontSee('data-assistant-state="available"', false)
            ->assertDontSee('Ask</span>', false);

        $this->ask($vendor, 'hello')
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', $expectedCode);

        $this->assertSame(0, $this->mockProvider()->callCount(), 'the provider is never reached');
        $this->assertFalse(app(DeveloperAssistant::class)->isAvailable());
    }

    public function test_the_shipped_defaults_keep_everything_off(): void
    {
        $defaults = require config_path('ai.php');

        $this->assertFalse($defaults['enabled'], 'global AI switch is off by default');
        $this->assertFalse($defaults['surfaces']['developer']['enabled'], 'the developer surface is off by default');
        $this->assertFalse($defaults['surfaces']['vendor']['enabled']);
        $this->assertFalse($defaults['surfaces']['customer']['enabled']);
        $this->assertSame('mock', $defaults['provider'], 'no live provider is configured');
        $this->assertNull($defaults['default_model']);
        $this->assertNull($defaults['providers']['openai']['api_key'] ?? null, 'no provider credential ships');
        // M5.5 allow-lists the Vendor Assistant's six tools on `vendor` and M5.6 the Customer Assistant's four on `customer` (both inert while their surface is off).
        $this->assertSame(VendorAssistant::TOOLS, $defaults['tools']['allowlist']['vendor']);
        $this->assertSame(CustomerAssistant::TOOLS, $defaults['tools']['allowlist']['customer']);
    }

    public function test_the_global_kill_switch_blocks_the_assistant(): void
    {
        config(['ai.enabled' => false]);

        $this->assertUnavailable('ai_disabled');
    }

    public function test_the_runtime_kill_switch_blocks_the_assistant_immediately(): void
    {
        $vendor = $this->approvedVendor();
        $this->ask($vendor, 'hello')->assertOk();

        app(KillSwitch::class)->engage();
        $before = $this->mockProvider()->callCount();

        $this->ask($vendor, 'hello again')->assertStatus(503)->assertJsonPath('error.code', 'ai_disabled');
        $this->assertSame($before, $this->mockProvider()->callCount());

        app(KillSwitch::class)->release();
        $this->ask($vendor, 'and again')->assertOk();
    }

    public function test_a_disabled_developer_surface_blocks_the_assistant_even_when_the_engine_is_on(): void
    {
        config(['ai.enabled' => true, 'ai.surfaces.developer.enabled' => false]);

        $this->assertUnavailable('ai_disabled');
    }

    public function test_other_surfaces_being_on_does_not_enable_the_developer_assistant(): void
    {
        config(['ai.surfaces.developer.enabled' => false, 'ai.surfaces.vendor.enabled' => true, 'ai.surfaces.customer.enabled' => true]);

        $this->assertUnavailable('ai_disabled');
    }

    public function test_missing_or_unsupported_provider_configuration_fails_closed(): void
    {
        foreach ([null, '', 'openai', 'something-else'] as $provider) {
            config(['ai.provider' => $provider]);
            $this->assertUnavailable('ai_misconfigured');
        }
    }

    public function test_the_offline_mock_never_answers_in_production(): void
    {
        // Service level: switching the environment would also switch CSRF enforcement on for HTTP requests.
        $context = $this->developerContext();
        $this->app['env'] = 'production';

        $this->assertFalse(app(DeveloperAssistant::class)->isAvailable());

        try {
            app(DeveloperAssistant::class)->ask($context, 'hello');
            $this->fail('the mock provider answered in production');
        } catch (AiConfigurationException) {
            $this->assertSame(0, $this->mockProvider()->callCount());
        }
    }

    public function test_an_empty_allowlist_makes_the_assistant_unavailable_and_denies_every_tool(): void
    {
        config(['ai.tools.allowlist.developer' => []]);

        $this->assertUnavailable('ai_misconfigured');

        $context = $this->developerContext();
        $this->assertSame('unknown_tool', $this->runTool('developer_search_docs', ['query' => 'scopes'], $context)->errorCode);
        $this->assertSame([], app(ToolRegistry::class)->definitionsFor(AiSurface::Developer));
    }

    public function test_an_incorrect_or_malformed_allowlist_fails_closed(): void
    {
        foreach ([['developer_list_my_apps'], ['developer_search_docs'], ['not_a_tool'], 'developer_search_docs', null, false] as $bad) {
            config(['ai.tools.allowlist.developer' => $bad]);
            $this->assertFalse(app(DeveloperAssistant::class)->isAvailable(), 'docs tools are required: '.json_encode($bad));
        }

        config(['ai.tools.allowlist.developer' => ['developer_search_docs']]);
        $this->assertUnavailable('ai_misconfigured');
    }

    public function test_a_tool_that_is_not_on_the_allowlist_is_denied_even_if_the_assistant_is_available(): void
    {
        config(['ai.tools.allowlist.developer' => ['developer_search_docs', 'developer_read_doc']]);
        $this->assertTrue(app(DeveloperAssistant::class)->isAvailable());

        $names = array_column(app(ToolRegistry::class)->definitionsFor(AiSurface::Developer), 'name');
        $this->assertSame(['developer_search_docs', 'developer_read_doc'], $names);

        $result = $this->runTool('developer_list_my_apps', [], $this->developerContext());
        $this->assertSame('unknown_tool', $result->errorCode);
        $this->assertFalse($result->wasExecuted());
    }

    public function test_vendor_and_customer_surfaces_cannot_discover_or_execute_developer_tools(): void
    {
        $registry = app(ToolRegistry::class);

        // (M5.5: the vendor surface now offers its OWN tools, but never a developer one.)
        $offered = array_map(fn ($d) => $d['name'], $registry->definitionsFor(AiSurface::Vendor));
        $this->assertSame([], array_values(array_intersect($offered, DeveloperAssistant::TOOLS)));
        // (M5.6: the customer surface offers its OWN four tools, but never a developer one.)
        $customerOffered = array_map(fn ($d) => $d['name'], $registry->definitionsFor(AiSurface::Customer));
        $this->assertSame([], array_values(array_intersect($customerOffered, DeveloperAssistant::TOOLS)));

        // Even if someone mistakenly allow-lists the developer tools on the other surfaces, the tools do not declare them.
        config(['ai.tools.allowlist.vendor' => DeveloperAssistant::TOOLS, 'ai.tools.allowlist.customer' => DeveloperAssistant::TOOLS]);
        $this->assertSame([], $registry->definitionsFor(AiSurface::Vendor));
        $this->assertSame([], $registry->definitionsFor(AiSurface::Customer));

        $vendorContext = ToolContext::forVendor($this->approvedVendor());
        $guestContext = ToolContext::forGuest(str_repeat('s', 40));

        foreach (DeveloperAssistant::TOOLS as $name) {
            foreach ([$vendorContext, $guestContext] as $context) {
                $args = $name === 'developer_get_my_app' ? ['app_ref' => 1] : (str_contains($name, 'docs') ? ['query' => 'scopes'] : ($name === 'developer_read_doc' ? ['doc' => 'scopes'] : []));
                $result = $this->runTool($name, $args, $context);

                $this->assertSame('unknown_tool', $result->errorCode, "{$name} on {$context->surface->value}");
                $this->assertFalse($result->wasExecuted());
            }
        }
    }

    public function test_the_registry_is_sealed_and_holds_exactly_the_developer_and_vendor_tools(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->isSealed());
        $this->assertEqualsCanonicalizing(array_merge(DeveloperAssistant::TOOLS, VendorAssistant::TOOLS, CustomerAssistant::TOOLS), $registry->names());
        $this->assertSame(DeveloperAssistant::TOOLS, config('ai.tools.allowlist.developer'));

        foreach (DeveloperAssistant::TOOLS as $name) {
            $this->assertSame([AiSurface::Developer], $registry->get($name)->surfaces(), $name.' is developer-only');
        }

        $this->expectException(AiToolRegistrationException::class);
        $registry->register(new FakeTool('late_tool', [AiSurface::Developer]));
    }

    public function test_a_broken_tool_limit_configuration_fails_closed_without_reaching_the_provider(): void
    {
        config(['ai.tools.limits.max_calls_per_turn' => 0]);
        $this->mockProvider()->queue($this->toolCallResponse([$this->toolCall('developer_search_docs', ['query' => 'scopes'])]));

        $this->ask($this->approvedVendor(), 'scopes?')->assertStatus(503);
        $this->assertSame(1, $this->mockProvider()->callCount(), 'only the first (tool-less) round happened; no tool ran');
    }

    public function test_engine_gates_still_apply_rate_limit_budget_and_concurrency(): void
    {
        $vendor = $this->approvedVendor();

        // M5.2 rate limit (per principal, per surface).
        config(['ai.rate_limits.developer.per_minute' => 1]);
        $this->ask($vendor, 'one')->assertOk();
        $this->ask($vendor, 'two')->assertStatus(429)->assertJsonPath('error.code', 'ai_rate_limited');
        $this->assertSame(1, $this->mockProvider()->callCount());
        config(['ai.rate_limits.developer.per_minute' => 1000]);

        // M5.2 shared concurrency slots.
        $locks = [Cache::lock('ai:slot:0', 60), Cache::lock('ai:slot:1', 60)];
        foreach ($locks as $lock) {
            $this->assertTrue($lock->get());
        }
        $this->ask($vendor, 'busy?')->assertStatus(503)->assertJsonPath('error.code', 'ai_busy');
        foreach ($locks as $lock) {
            $lock->release();
        }
        $this->assertSame(1, $this->mockProvider()->callCount());
        $this->ask($vendor, 'free again')->assertOk();
    }

    public function test_the_m52_budget_is_enforced_for_the_developer_surface(): void
    {
        $live = $this->useLiveProvider();
        $this->liveBudgets([
            'ai.limits.surfaces.developer' => ['daily_requests' => 100, 'daily_tokens' => 0, 'daily_cost_micro_usd' => 1_000_000], // 0 = hard block
        ]);

        $this->ask($this->approvedVendor(), 'hello')->assertStatus(503)->assertJsonPath('error.code', 'ai_budget_exhausted');
        $this->assertSame(0, $live->calls, 'a blocked budget never reaches the provider');
    }

    public function test_a_live_provider_without_budgets_fails_closed(): void
    {
        $live = $this->useLiveProvider(); // no token limits configured

        $this->ask($this->approvedVendor(), 'hello')->assertStatus(503);
        $this->assertSame(0, $live->calls);
    }

    public function test_the_assistant_never_bypasses_the_orchestrator(): void
    {
        foreach (glob(app_path('Services/Ai/Developer/*.php')) as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringNotContainsString('->send(', $src, basename($file).' must not call a provider directly');
            $this->assertStringNotContainsString('MockAiProvider', $src, basename($file));
        }

        $assistant = (string) file_get_contents(app_path('Services/Ai/Developer/DeveloperAssistant.php'));
        $this->assertSame(2, substr_count($assistant, '$this->orchestrator->run('), 'exactly two engine requests in a turn: before and after the tool batch');
    }

    public function test_the_assistant_requires_a_developer_context(): void
    {
        $this->expectException(AiDisabledException::class);
        app(DeveloperAssistant::class)->ask(ToolContext::forVendor($this->approvedVendor()), 'hello');
    }

    public function test_configuration_exceptions_have_safe_user_messages(): void
    {
        config(['ai.tools.allowlist.developer' => []]);

        try {
            app(DeveloperAssistant::class)->ask($this->developerContext(), 'hello');
            $this->fail('expected a configuration failure');
        } catch (AiConfigurationException $e) {
            $this->assertStringNotContainsString('allowlist', strtolower($e->userMessage()));
        }
    }
}
