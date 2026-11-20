<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Providers\AiProviderManager;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Feature\Ai\Support\FakeLiveProvider;
use Tests\TestCase;

/**
 * Engine ON, offline mock provider, no limits, logging captured in memory. Every test also proves that the
 * engine never sent an HTTP request (Http is faked and asserted empty in tearDown): the AI foundation has no
 * network path at all.
 */
abstract class AiTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config([
            'ai.enabled' => true,
            'ai.provider' => 'mock',
            'ai.default_model' => null,
            'ai.surfaces.customer.enabled' => true,
            'ai.surfaces.vendor.enabled' => true,
            'ai.surfaces.developer.enabled' => true,
            'ai.rate_limits.customer.per_minute' => 1000,
            'ai.rate_limits.vendor.per_minute' => 1000,
            'ai.rate_limits.developer.per_minute' => 1000,
            'ai.concurrency.max_in_flight' => 2,
            'ai.concurrency.lock_seconds' => 45,
            'ai.request.max_retries' => 0,
            'ai.request.timeout_seconds' => 20,
            'ai.request.max_timeout_seconds' => 30,
            'ai.request.deadline_seconds' => 40,
            'ai.request.max_request_bytes' => 16000,
            'ai.request.max_context_messages' => 20,
            'ai.request.max_output_tokens' => 512,
            'ai.circuit_breaker' => ['failure_threshold' => 3, 'window_seconds' => 120, 'cooldown_seconds' => 60],
            'ai.pricing' => [],
            'ai.limits.global' => ['daily_requests' => null, 'daily_tokens' => null, 'daily_cost_micro_usd' => null],
            'ai.limits.surfaces' => [
                'customer' => ['daily_requests' => null, 'daily_tokens' => null, 'daily_cost_micro_usd' => null],
                'vendor' => ['daily_requests' => null, 'daily_tokens' => null, 'daily_cost_micro_usd' => null],
                'developer' => ['daily_requests' => null, 'daily_tokens' => null, 'daily_cost_micro_usd' => null],
            ],
            'logging.channels.ai_test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'ai.logging.channel' => 'ai_test',
        ]);

        Cache::flush();
        $this->releaseEngineLocks();
        $this->mockProvider()->reset();
    }

    /** Cache::flush() does not clear locks on the database store; free every lock the engine can hold. */
    private function releaseEngineLocks(): void
    {
        foreach (range(0, 5) as $i) {
            Cache::lock("ai:slot:{$i}")->forceRelease();
        }
        foreach (['mock', 'fakelive'] as $provider) {
            Cache::lock("ai:cb:{$provider}:probe")->forceRelease();
        }
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Carbon::setTestNow();
        $this->releaseEngineLocks();

        parent::tearDown();
    }

    protected function mockProvider(): MockAiProvider
    {
        return app(MockAiProvider::class);
    }

    protected function engine(): AiOrchestrator
    {
        return app(AiOrchestrator::class);
    }

    protected function req(string $text = 'Hello', AiSurface $surface = AiSurface::Vendor, string $principal = 'vendor:1', array $options = []): AiRequest
    {
        return new AiRequest(
            $surface,
            $principal,
            [AiMessage::user($text)],
            $options['model'] ?? null,
            $options['maxOutputTokens'] ?? null,
            $options['timeoutSeconds'] ?? null,
        );
    }

    /** @return array<string,array<string,int>> */
    protected function usage(): array
    {
        return app(UsageMeter::class)->snapshot();
    }

    protected function useProvider(AiProvider $provider): AiProvider
    {
        $this->app->instance(AiProviderManager::class, new class($provider) extends AiProviderManager
        {
            public function __construct(private readonly AiProvider $fixed) {}

            public function provider(): AiProvider
            {
                return $this->fixed;
            }
        });

        return $provider;
    }

    protected function useLiveProvider(): FakeLiveProvider
    {
        $this->useProvider($live = new FakeLiveProvider);
        config(['ai.provider' => 'fakelive', 'ai.default_model' => 'test-model']);

        return $live;
    }

    /** @return list<array{message:string,level:string,context:array<string,mixed>}> */
    protected function logRecords(): array
    {
        $handler = Log::channel('ai_test')->getLogger()->getHandlers()[0];

        return array_map(
            fn ($r) => ['message' => $r->message, 'level' => $r->level->getName(), 'context' => $r->context],
            $handler->getRecords()
        );
    }

    /** Everything that reached the log, as one string — for "must not appear" assertions. */
    protected function logDump(): string
    {
        return json_encode($this->logRecords());
    }
}
