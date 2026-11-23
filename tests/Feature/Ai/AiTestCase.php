<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Providers\AiProviderManager;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Safety\CacheEngineCheck;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Feature\Ai\Support\DurableAiState;
use Tests\Feature\Ai\Support\FakeLiveProvider;
use Tests\TestCase;

/**
 * Engine ON, offline mock provider, no limits, logging captured in memory. Every test also proves that the
 * engine never sent an HTTP request (Http is faked and asserted empty in tearDown): the AI foundation has no
 * network path at all.
 */
abstract class AiTestCase extends TestCase
{
    use DurableAiState;

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

        $this->prepareDurableAiState();
        Cache::flush();
        $this->releaseEngineLocks();
        $this->mockProvider()->reset();
    }

    /**
     * Start over mid-test: an empty cache, free locks and an empty durable budget ledger with the kill switch released.
     * (Cache::flush() alone no longer resets the budget - that is the point of M5.7.1.)
     */
    protected function freshEngineState(): void
    {
        Cache::flush();
        $this->releaseEngineLocks();
        $this->prepareDurableAiState();
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
        if (! $this->allowsFakedHttp()) {
            Http::assertNothingSent();
        }
        Carbon::setTestNow();
        $this->releaseEngineLocks();
        $this->removeAnchorDir();

        parent::tearDown();
    }

    /**
     * Only the OpenAI-adapter tests return true: they exercise the real adapter against Http::fake() (and prevent stray
     * requests, so an unfaked URL throws instead of leaving the machine). Every other AI test proves nothing was sent.
     */
    protected function allowsFakedHttp(): bool
    {
        return false;
    }

    /** The shared-cache verdict used by live-provider gates: the test cache is `array`, which the real check rightly rejects. */
    protected function fakeCacheEngine(bool $suitable = true): void
    {
        $this->app->instance(CacheEngineCheck::class, new class($suitable) extends CacheEngineCheck
        {
            public function __construct(private readonly bool $ok) {}

            public function inspect(): array
            {
                return ['driver' => 'fake', 'store_driver' => 'fake', 'connection' => null, 'suitable' => $this->ok, 'summary' => 'fake', 'tables' => [], 'notes' => []];
            }

            public function inspectOnce(): array
            {
                return $this->inspect();
            }
        });
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

    /**
     * Make the database fail the moment a statement containing `$needle` has run (inside whatever transaction is open), the way
     * a dropped connection, a lock timeout or a full disk would. The fault fires ONCE, on the $nth-th matching statement. Returns a\n     * closure that disarms it.
     */
    protected function failDatabaseWhen(string $needle, int $nth = 1): \Closure
    {
        $armed = true;
        $seen = 0;

        DB::listen(function ($query) use ($needle, $nth, &$armed, &$seen): void {
            // Identifier quoting differs per driver ("x" on SQLite, `x` on MySQL/MariaDB): match on the bare statement text.
            if ($armed && str_contains(str_replace(['"', '`'], '', $query->sql), $needle) && ++$seen === $nth) {
                throw new \RuntimeException('simulated database failure');
            }
        });

        return function () use (&$armed): void {
            $armed = false;
        };
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

    /**
     * Everything a LIVE provider needs before it may run (M5.7): explicit finite requests/tokens/cost limits on the global
     * scope and every surface, and complete pricing for the fake live provider's model. Values are test fixtures only.
     *
     * @param  array<string,mixed>  $overrides  config overrides applied afterwards
     */
    protected function liveBudgets(array $overrides = []): void
    {
        // M5.7.2: a live provider needs the independent anchor (a throw-away one here).
        if ($this->anchorDir === null) {
            $this->useIntegrityAnchor();
            app(\App\Services\Ai\Safety\KillSwitch::class)->release('test setup');
        }

        $limit = ['daily_requests' => 1000, 'daily_tokens' => 10_000_000, 'daily_cost_micro_usd' => 1_000_000_000];

        config([
            'ai.limits.global' => $limit,
            'ai.limits.surfaces.customer' => $limit,
            'ai.limits.surfaces.vendor' => $limit,
            'ai.limits.surfaces.developer' => $limit,
            'ai.pricing.fakelive.test-model' => ['input_usd_per_million' => 2.0, 'output_usd_per_million' => 6.0, 'as_of' => '2026-01-01'],
            'ai.request.turn_deadline_seconds' => 60,
        ]);

        config($overrides);
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
