<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiCircuitOpenException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Safety\AiRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ResilienceTest extends AiTestCase
{
    private function failRuns(int $times, ?AiProviderException $with = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->mockProvider()->failWith($with ?? AiProviderException::unavailable());
            try {
                $this->engine()->run($this->req());
            } catch (AiProviderException) {
            }
        }
    }

    // ---------------------------------------------------------------- concurrency

    public function test_requests_are_refused_when_every_shared_slot_is_taken(): void
    {
        $a = Cache::lock('ai:slot:0', 60);
        $b = Cache::lock('ai:slot:1', 60);
        $this->assertTrue($a->get());
        $this->assertTrue($b->get());

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the engine to be busy.');
        } catch (AiBusyException $e) {
            $this->assertSame('ai_busy', $e->errorCode());
            $this->assertNotNull($e->retryAfterSeconds);
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->assertSame(0, $this->usage()['global']['requests'], 'a busy refusal consumes no budget');

        $b->release();
        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider, 'a freed slot is usable again');
    }

    public function test_a_call_in_flight_holds_a_slot_that_blocks_others_beyond_the_limit(): void
    {
        config(['ai.concurrency.max_in_flight' => 1]);
        $inner = null;

        $this->mockProvider()->queue(function () use (&$inner) {
            try {
                $this->engine()->run($this->req('nested', principal: 'vendor:2'));
            } catch (\Throwable $e) {
                $inner = $e;
            }
        });

        $this->engine()->run($this->req());

        $this->assertInstanceOf(AiBusyException::class, $inner, 'a second request during an in-flight call must not be admitted');
    }

    public function test_the_slot_is_released_after_success_and_after_failure(): void
    {
        config(['ai.concurrency.max_in_flight' => 1]);

        $this->engine()->run($this->req());
        $this->mockProvider()->failWith(AiProviderException::unavailable());
        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        // If either path leaked the single slot, this would throw AiBusyException.
        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider);
    }

    // ---------------------------------------------------------------- rate limiting

    public function test_rate_limiting_is_per_principal_and_per_surface(): void
    {
        config(['ai.rate_limits.vendor.per_minute' => 2, 'ai.rate_limits.customer.per_minute' => 2]);

        $this->engine()->run($this->req(principal: 'vendor:1'));
        $this->engine()->run($this->req(principal: 'vendor:1'));

        try {
            $this->engine()->run($this->req(principal: 'vendor:1'));
            $this->fail('Expected the third request in a minute to be rate limited.');
        } catch (AiRateLimitedException $e) {
            $this->assertSame('ai_rate_limited', $e->errorCode());
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }

        $this->assertSame(2, $this->mockProvider()->callCount());

        // Another vendor, and the same identity string on another surface, have their own allowance.
        $this->engine()->run($this->req(principal: 'vendor:2'));
        $this->engine()->run($this->req('hi', AiSurface::Customer, 'vendor:1'));
        $this->assertSame(4, $this->mockProvider()->callCount());
    }

    public function test_rate_limit_refusals_do_not_consume_budget(): void
    {
        config(['ai.rate_limits.vendor.per_minute' => 1]);
        $this->engine()->run($this->req());
        $before = $this->usage()['global']['requests'];

        try {
            $this->engine()->run($this->req());
        } catch (AiRateLimitedException) {
        }

        $this->assertSame($before, $this->usage()['global']['requests']);
    }

    // ---------------------------------------------------------------- circuit breaker

    public function test_the_circuit_opens_after_repeated_provider_failures_and_stops_calling_it(): void
    {
        $this->failRuns(3);
        $this->assertSame(3, $this->mockProvider()->callCount());

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the circuit to be open.');
        } catch (AiCircuitOpenException $e) {
            $this->assertSame('ai_unavailable', $e->errorCode());
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }

        $this->assertSame(3, $this->mockProvider()->callCount(), 'an open circuit never reaches the provider');
        $this->assertSame(3, $this->usage()['global']['requests'], 'refused-by-circuit requests consume no budget');
    }

    public function test_failures_below_the_threshold_do_not_open_the_circuit(): void
    {
        $this->failRuns(2);

        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $this->failRuns(2);
        $this->engine()->run($this->req());
        $this->failRuns(2);

        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider, '2 + success + 2 is not 3 consecutive failures');
    }

    public function test_bad_requests_and_auth_errors_do_not_count_against_the_provider(): void
    {
        $this->failRuns(4, new AiProviderException(AiProviderException::BAD_REQUEST));
        $this->failRuns(4, new AiProviderException(AiProviderException::AUTHENTICATION));

        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider);
    }

    public function test_after_the_cooldown_one_probe_is_allowed_and_success_closes_the_circuit(): void
    {
        $this->failRuns(3);
        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider, 'the half-open probe succeeds');
        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider, 'the circuit is closed again');
    }

    public function test_a_failed_probe_reopens_the_circuit_for_another_cooldown(): void
    {
        $this->failRuns(3);
        Carbon::setTestNow(now()->addSeconds(61));

        $this->failRuns(1, AiProviderException::timeout()); // the probe fails

        $this->expectException(AiCircuitOpenException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            Carbon::setTestNow(now()->addSeconds(61));
            $this->assertSame('mock', $this->engine()->run($this->req())->response->provider, 'recovers after the second cooldown');
        }
    }

    public function test_only_a_single_probe_runs_at_a_time_while_half_open(): void
    {
        $this->failRuns(3);
        Carbon::setTestNow(now()->addSeconds(61));
        $second = null;

        $this->mockProvider()->queue(function () use (&$second) {
            try {
                $this->engine()->run($this->req('nested', principal: 'vendor:2'));
            } catch (\Throwable $e) {
                $second = $e;
            }
        });

        $this->engine()->run($this->req());

        $this->assertInstanceOf(AiCircuitOpenException::class, $second, 'while the probe is in flight everyone else is still refused');
    }

    // ---------------------------------------------------------------- timeouts and retries

    public function test_a_provider_timeout_is_a_safe_failure(): void
    {
        $this->mockProvider()->timeoutOnce();

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected a timeout.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::TIMEOUT, $e->kind);
            $this->assertSame('The assistant is temporarily unavailable. Please try again later.', $e->userMessage());
        }

        $this->assertSame(1, $this->mockProvider()->callCount(), 'no retries unless configured');
    }

    public function test_one_configured_retry_recovers_from_a_transient_timeout(): void
    {
        config(['ai.request.max_retries' => 1]);
        $this->mockProvider()->timeoutOnce()->respondWith('recovered');

        $result = $this->engine()->run($this->req());

        $this->assertSame('recovered', $result->response->text);
        $this->assertSame(2, $result->attempts);
    }

    public function test_retries_are_capped_even_if_configured_absurdly_high(): void
    {
        config(['ai.request.max_retries' => 50]);
        for ($i = 0; $i < 10; $i++) {
            $this->mockProvider()->timeoutOnce();
        }

        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        $this->assertSame(3, $this->mockProvider()->callCount(), '1 attempt + at most 2 retries');
    }

    public function test_non_retryable_errors_are_not_retried(): void
    {
        config(['ai.request.max_retries' => 2]);
        $this->mockProvider()->failWith(AiProviderException::rateLimited(30));

        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        $this->assertSame(1, $this->mockProvider()->callCount());
    }

    public function test_no_retry_starts_when_it_cannot_finish_inside_the_turn_deadline(): void
    {
        config(['ai.request.max_retries' => 2, 'ai.request.deadline_seconds' => 1]);
        $this->mockProvider()->timeoutOnce()->timeoutOnce();

        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        $this->assertSame(1, $this->mockProvider()->callCount());
        $this->assertSame(1, $this->mockProvider()->calls()[0]->timeoutSeconds, 'a single attempt is never allowed to outlive the turn deadline');
    }

    // ---------------------------------------------------------------- failing safely

    public function test_a_foreign_exception_from_a_provider_is_replaced_not_leaked(): void
    {
        $this->mockProvider()->queue(new \RuntimeException('Authorization: Bearer abcdefgh12345678 key sk-verysecretvalue123'));

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected a provider failure.');
        } catch (AiProviderException $e) {
            $this->assertStringNotContainsString('abcdefgh12345678', $e->getMessage());
            $this->assertStringNotContainsString('sk-verysecretvalue123', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the original exception (and its headers) must not travel with ours');
        }
    }

    public function test_an_unexpected_engine_failure_fails_closed_without_leaking_its_message(): void
    {
        $this->app->instance(AiRateLimiter::class, new class extends AiRateLimiter
        {
            public function __construct() {}

            public function attempt(AiRequest $request): void
            {
                throw new \RuntimeException('connection to db failed password=hunter2');
            }
        });

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the engine to fail closed.');
        } catch (AiConfigurationException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
            $this->assertStringContainsString('RuntimeException', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->assertStringNotContainsString('hunter2', $this->logDump());
    }
}
