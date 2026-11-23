<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiDisabledException;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Safety\KillSwitch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Ai\Support\DurableAiState;
use Tests\TestCase;

/**
 * Deliberately does NOT extend AiTestCase: the first tests must see the configuration exactly as shipped.
 */
class ConfigurationAndKillSwitchTest extends TestCase
{
    use DurableAiState;

    protected function setUp(): void
    {
        parent::setUp();

        // Refused requests are logged (redacted metadata); keep tests from writing to the real log file.
        config(['ai.logging.channel' => 'null']);
    }

    private function request(AiSurface $surface = AiSurface::Vendor): AiRequest
    {
        return new AiRequest($surface, 'vendor:1', [AiMessage::user('Hello')]);
    }

    private function enable(): void
    {
        $this->prepareDurableAiState(); // the durable tables, kill switch RELEASED (the shipped state is engaged)
        config([
            'ai.enabled' => true, 'ai.provider' => 'mock',
            'ai.surfaces.vendor.enabled' => true, 'ai.surfaces.customer.enabled' => true, 'ai.surfaces.developer.enabled' => true,
        ]);
    }

    public function test_the_shipped_configuration_is_off_and_safe(): void
    {
        $this->assertFalse(config('ai.enabled'));
        $this->assertSame('mock', config('ai.provider'));
        $this->assertNull(config('ai.default_model'), 'no model name is shipped');
        $this->assertSame([], config('ai.pricing'), 'no pricing is shipped');

        foreach (['customer', 'vendor', 'developer'] as $surface) {
            $this->assertFalse(config("ai.surfaces.{$surface}.enabled"));
            $this->assertNull(config("ai.limits.surfaces.{$surface}.daily_tokens"), 'budgets are not-configured, never "unlimited by default"');
        }
        $this->assertNull(config('ai.limits.global.daily_tokens'));
    }

    public function test_a_disabled_engine_refuses_without_touching_the_provider_or_the_network(): void
    {
        Http::fake();
        // 'openai' would throw a configuration error if the provider were even resolved.
        // Getting AiDisabledException proves the engine stopped before resolving it.
        config(['ai.provider' => 'openai']);
        $mock = app(MockAiProvider::class);

        try {
            app(AiOrchestrator::class)->run($this->request());
            $this->fail('Expected the disabled engine to refuse.');
        } catch (AiDisabledException $e) {
            $this->assertSame('ai_disabled', $e->errorCode());
        }

        $this->assertSame(0, $mock->callCount());
        Http::assertNothingSent();
    }

    public function test_a_disabled_surface_is_refused_even_when_the_engine_is_on(): void
    {
        $this->enable();
        config(['ai.surfaces.customer.enabled' => false]);
        $mock = app(MockAiProvider::class);

        $this->expectException(AiDisabledException::class);
        try {
            app(AiOrchestrator::class)->run($this->request(AiSurface::Customer));
        } finally {
            $this->assertSame(0, $mock->callCount());
        }
    }

    public function test_the_runtime_kill_switch_overrides_enabled_config_and_can_be_released(): void
    {
        $this->enable();
        $switch = app(KillSwitch::class);

        $this->assertTrue($switch->engineEnabled());

        $switch->engage();
        $this->assertFalse($switch->engineEnabled());
        $this->assertFalse($switch->surfaceEnabled(AiSurface::Vendor));
        try {
            app(AiOrchestrator::class)->run($this->request());
            $this->fail('Expected refusal while the kill switch is engaged.');
        } catch (AiDisabledException) {
        }
        $this->assertSame(0, app(MockAiProvider::class)->callCount());

        $switch->release();
        $this->assertTrue($switch->engineEnabled());
        $this->assertSame('mock', app(AiOrchestrator::class)->run($this->request())->response->provider);
    }

    public function test_the_kill_switch_fails_closed_when_its_state_cannot_be_read(): void
    {
        $this->enable();
        $switch = app(KillSwitch::class);
        $this->assertFalse($switch->engaged());

        Schema::drop('ai_runtime_flags'); // the state can no longer be read

        $this->assertTrue($switch->engaged());
        $this->assertFalse($switch->engineEnabled());
    }

    public function test_the_artisan_command_engages_releases_and_reports(): void
    {
        $this->enable();

        $this->artisan('ai:kill-switch', ['action' => 'engage'])->assertExitCode(0);
        $this->assertTrue(app(KillSwitch::class)->engaged());

        $this->artisan('ai:kill-switch', ['action' => 'status'])->expectsOutputToContain('ENGAGED')->assertExitCode(0);

        $this->artisan('ai:kill-switch', ['action' => 'release'])->assertExitCode(0);
        $this->assertFalse(app(KillSwitch::class)->engaged());

        $this->artisan('ai:kill-switch', ['action' => 'bogus'])->assertExitCode(2);
    }

    public function test_unimplemented_or_unknown_providers_fail_closed_with_no_network(): void
    {
        $this->enable();
        Http::fake();

        foreach (['openai', 'nonsense', null, ''] as $provider) {
            config(['ai.provider' => $provider]);
            try {
                app(AiOrchestrator::class)->run($this->request());
                $this->fail('Expected a configuration error for provider ['.var_export($provider, true).'].');
            } catch (AiConfigurationException $e) {
                $this->assertSame('ai_misconfigured', $e->errorCode());
            }
        }

        Http::assertNothingSent();
    }

    public function test_missing_or_invalid_engine_settings_fail_safely_before_the_provider(): void
    {
        $this->enable();
        config([
            'ai.rate_limits.vendor.per_minute' => 1000,
            'ai.concurrency.max_in_flight' => 2, 'ai.concurrency.lock_seconds' => 45,
            'ai.circuit_breaker' => ['failure_threshold' => 3, 'window_seconds' => 120, 'cooldown_seconds' => 60],
        ]);
        $mock = app(MockAiProvider::class);

        $broken = [
            'ai.request.max_request_bytes' => null,
            'ai.rate_limits.vendor.per_minute' => null,
            'ai.concurrency.max_in_flight' => 0,
            'ai.circuit_breaker.failure_threshold' => null,
        ];

        foreach ($broken as $key => $value) {
            $original = config($key);
            config([$key => $value]);

            try {
                app(AiOrchestrator::class)->run($this->request());
                $this->fail("Expected a configuration error when [{$key}] is invalid.");
            } catch (AiConfigurationException) {
            }

            config([$key => $original]);
        }

        $this->assertSame(0, $mock->callCount());
    }

    public function test_no_conversation_or_message_storage_exists_in_this_milestone(): void
    {
        foreach (['ai_conversations', 'ai_messages', 'ai_runs', 'ai_tool_calls', 'ai_purchase_proposals'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist yet");
        }
    }

    public function test_no_public_ai_routes_exist(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->implode("\n");

        $this->assertDoesNotMatchRegularExpression('#(^|/)ai(/|$)#m', $uris);
    }
}
