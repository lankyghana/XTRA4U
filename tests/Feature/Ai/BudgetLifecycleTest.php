<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Safety\CircuitBreaker;
use App\Services\Ai\Safety\CircuitPermit;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\TokenEstimator;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The complete reservation -> settlement lifecycle, case by case (M5.2 safety verification).
 *
 * Invariant under test: budget is released ONLY for tokens that provably were not processed. Anything that may have
 * reached the provider (success with unknown usage, timeout, 5xx after sending, garbage reply, a foreign exception,
 * bookkeeping failing after a good answer) stays charged.
 */
class BudgetLifecycleTest extends AiTestCase
{
    private const PRICE = ['input_usd_per_million' => 2.5, 'output_usd_per_million' => 10];

    /** The pessimistic estimate the engine reserved for the (only/last) provider call of this test. */
    private function estimateOfLastCall(): int
    {
        $calls = $this->mockProvider()->calls();

        return app(TokenEstimator::class)->estimate(end($calls));
    }

    private function runAndExpectFailure(): AiProviderException
    {
        try {
            $this->engine()->run($this->req());
            $this->fail('Expected a provider failure.');
        } catch (AiProviderException $e) {
            return $e;
        }
    }

    // ------------------------------------------------ case 1: refused before the provider

    public function test_requests_refused_before_the_provider_leave_every_counter_at_zero(): void
    {
        // disabled engine
        config(['ai.enabled' => false]);
        try {
            $this->engine()->run($this->req());
        } catch (\Throwable) {
        }

        // live provider without budgets (ai_misconfigured)
        config(['ai.enabled' => true]);
        $this->useLiveProvider();
        try {
            $this->engine()->run($this->req());
        } catch (AiConfigurationException) {
        }

        foreach ($this->usage() as $scope => $metrics) {
            foreach ($metrics as $metric => $value) {
                $this->assertSame(0, $value, "{$scope}.{$metric} must be untouched by a request that never reached the provider");
            }
        }
    }

    // ------------------------------------------------ cases 2-3: success

    public function test_known_usage_below_the_estimate_releases_only_the_unused_part(): void
    {
        $this->mockProvider()->respondWith('ok', new AiUsage(30, 20));

        $this->engine()->run($this->req());

        $this->assertGreaterThan(50, $this->estimateOfLastCall());
        $this->assertSame(50, $this->usage()['global']['tokens'], 'charged exactly the reported 50 tokens');
        $this->assertSame(50, $this->usage()['surface.vendor']['tokens']);
    }

    public function test_known_usage_above_the_estimate_is_recorded_in_full(): void
    {
        $this->mockProvider()->respondWith('ok', new AiUsage(90_000, 500));

        $this->engine()->run($this->req());

        $this->assertSame(90_500, $this->usage()['global']['tokens'], 'accounting records the truth even when the estimate was too low');
    }

    public function test_unknown_usage_keeps_the_whole_reservation_charged_and_a_conservative_cost(): void
    {
        config(['ai.pricing.mock.mock-model' => self::PRICE]);
        $this->mockProvider()->respondWith('ok', new AiUsage(null, null));

        $result = $this->engine()->run($this->req());

        $estimate = $this->estimateOfLastCall();
        $this->assertSame($estimate, $this->usage()['global']['tokens'], 'unknown usage is never released as if it were zero');
        $this->assertNull($result->estimatedCostMicroUsd, 'the caller is not shown a cost that was never reported');
        $this->assertSame((int) ceil($estimate * 10), $this->usage()['global']['cost_micro_usd'], 'but the cost budget is charged at the dearer rate');
        $this->assertSame($this->usage()['global']['cost_micro_usd'], $this->usage()['surface.vendor']['cost_micro_usd']);
    }

    public function test_unknown_usage_without_configured_pricing_charges_tokens_only(): void
    {
        $this->mockProvider()->respondWith('ok', new AiUsage(null, null));

        $this->engine()->run($this->req());

        $this->assertSame($this->estimateOfLastCall(), $this->usage()['global']['tokens']);
        $this->assertSame(0, $this->usage()['global']['cost_micro_usd'], 'pricing is never guessed');
    }

    // ------------------------------------------------ cases 4-5: timeout / provider error

    public function test_a_timeout_is_not_treated_as_zero_usage(): void
    {
        config(['ai.pricing.mock.mock-model' => self::PRICE]);
        $this->mockProvider()->timeoutOnce();

        $this->runAndExpectFailure();

        $estimate = $this->estimateOfLastCall();
        $u = $this->usage();
        $this->assertSame($estimate, $u['global']['tokens'], 'the request may have been processed: the estimate stays charged');
        $this->assertSame($estimate, $u['surface.vendor']['tokens']);
        $this->assertSame(1, $u['global']['requests']);
        $this->assertSame(1, $u['global']['failures']);
        $this->assertGreaterThan(0, $u['global']['cost_micro_usd'], 'and so does a conservative cost');
    }

    /** @return array<string,array{0:AiProviderException,1:bool}> */
    public static function failures(): array
    {
        return [
            'timeout' => [AiProviderException::timeout(), true],
            'unavailable (sent)' => [AiProviderException::unavailable(), true],
            'invalid response' => [new AiProviderException(AiProviderException::INVALID_RESPONSE), true],
            'unavailable (certainly not sent)' => [AiProviderException::unavailable('refused', requestSent: false), false],
            'rate limited' => [AiProviderException::rateLimited(30), false],
            'authentication' => [new AiProviderException(AiProviderException::AUTHENTICATION), false],
            'bad request' => [new AiProviderException(AiProviderException::BAD_REQUEST), false],
        ];
    }

    #[DataProvider('failures')]
    public function test_tokens_are_released_only_for_failures_that_provably_were_not_processed(AiProviderException $failure, bool $stillCharged): void
    {
        $this->mockProvider()->failWith($failure);

        $this->runAndExpectFailure();

        $estimate = $this->estimateOfLastCall();
        $u = $this->usage();
        $this->assertSame($stillCharged ? $estimate : 0, $u['global']['tokens']);
        $this->assertSame($stillCharged ? $estimate : 0, $u['surface.vendor']['tokens']);
        $this->assertSame(1, $u['global']['requests'], 'every attempt counts as a request');
        $this->assertSame(1, $u['global']['failures']);
    }

    public function test_a_foreign_exception_from_an_adapter_stays_charged(): void
    {
        $this->mockProvider()->queue(new \RuntimeException('socket closed after the request was written'));

        $this->runAndExpectFailure();

        $this->assertSame($this->estimateOfLastCall(), $this->usage()['global']['tokens']);
    }

    // ------------------------------------------------ case 6: retries

    public function test_a_retry_after_a_timeout_keeps_the_first_attempt_charged_and_counts_a_second_request(): void
    {
        config(['ai.request.max_retries' => 1]);
        $this->mockProvider()->timeoutOnce()->respondWith('ok', new AiUsage(40, 10));

        $this->engine()->run($this->req());

        $estimate = $this->estimateOfLastCall();
        $u = $this->usage();
        $this->assertSame($estimate + 50, $u['global']['tokens'], 'the timed-out attempt (estimate) plus the successful attempt (actual)');
        $this->assertSame(2, $u['global']['requests']);
        $this->assertSame(1, $u['global']['successes']);
        $this->assertSame(0, $u['global']['failures']);
    }

    public function test_a_retry_after_a_certainly_unsent_attempt_does_not_double_charge_tokens(): void
    {
        config(['ai.request.max_retries' => 1]);
        $this->mockProvider()->failWith(AiProviderException::unavailable('refused', requestSent: false))->respondWith('ok', new AiUsage(40, 10));

        $this->engine()->run($this->req());

        $u = $this->usage();
        $this->assertSame(50, $u['global']['tokens']);
        $this->assertSame(2, $u['global']['requests']);
    }

    public function test_two_ambiguous_attempts_are_both_charged(): void
    {
        config(['ai.request.max_retries' => 1]);
        $this->mockProvider()->timeoutOnce()->timeoutOnce();

        $this->runAndExpectFailure();

        $estimate = $this->estimateOfLastCall();
        $this->assertSame(2, $this->mockProvider()->callCount());
        $this->assertSame(2 * $estimate, $this->usage()['global']['tokens']);
        $this->assertSame(2, $this->usage()['global']['requests']);
    }

    public function test_a_retry_cannot_be_used_to_get_past_the_request_budget(): void
    {
        config(['ai.request.max_retries' => 1, 'ai.limits.global.daily_requests' => 1]);
        $this->mockProvider()->timeoutOnce()->respondWith('never reached');

        $e = $this->runAndExpectFailure();

        $this->assertSame(AiProviderException::TIMEOUT, $e->kind, 'the caller sees the original provider error');
        $this->assertSame(1, $this->mockProvider()->callCount(), 'the retry was not sent');
        $u = $this->usage();
        $this->assertSame(1, $u['global']['requests'], 'the refused retry left no residue');
        $this->assertSame($this->estimateOfLastCall(), $u['global']['tokens']);
    }

    public function test_a_retry_cannot_be_used_to_get_past_the_token_budget(): void
    {
        $this->mockProvider()->timeoutOnce(); // probe the estimate with a first run
        $this->runAndExpectFailure();
        $estimate = $this->estimateOfLastCall();
        Cache::flush();

        config(['ai.request.max_retries' => 1, 'ai.limits.global.daily_tokens' => $estimate + 10]); // room for ONE attempt
        $this->mockProvider()->reset()->timeoutOnce()->respondWith('never reached');

        $this->runAndExpectFailure();

        $this->assertSame(1, $this->mockProvider()->callCount());
        $this->assertSame($estimate, $this->usage()['global']['tokens']);
    }

    // ------------------------------------------------ case 7: partial reservations

    public function test_when_the_surface_budget_refuses_after_the_global_one_succeeded_everything_is_rolled_back(): void
    {
        config(['ai.limits.global.daily_requests' => 100, 'ai.limits.global.daily_tokens' => 1_000_000, 'ai.limits.surfaces.vendor.daily_tokens' => 5]);

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the surface token budget to refuse.');
        } catch (AiBudgetExceededException $e) {
            $this->assertStringContainsString('surface.vendor', $e->getMessage());
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        foreach (['global', 'surface.vendor'] as $scope) {
            $this->assertSame(0, $this->usage()[$scope]['requests'], "{$scope}.requests rolled back");
            $this->assertSame(0, $this->usage()[$scope]['tokens'], "{$scope}.tokens rolled back");
        }
    }

    public function test_a_cache_failure_part_way_through_a_reservation_rolls_back_what_was_taken(): void
    {
        $meter = $this->flakyMeter();
        $meter->failOnAddNumber = 3; // global.requests, global.tokens succeed; surface.requests blows up

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the engine to fail closed.');
        } catch (AiConfigurationException) {
        }

        $meter->failOnAddNumber = null;
        $this->assertSame(0, $this->mockProvider()->callCount(), 'no provider call with a half-made reservation');
        $this->assertSame(0, $this->usage()['global']['requests']);
        $this->assertSame(0, $this->usage()['global']['tokens']);
    }

    // ------------------------------------------------ idempotency

    public function test_settlement_and_failure_are_at_most_once_per_reservation(): void
    {
        $guard = app(BudgetGuard::class);

        $r = $guard->reserve(AiSurface::Vendor, 100, false);
        $guard->settle($r, 40, null);
        $guard->settle($r, 40, null);   // repeated
        $guard->fail($r, false);        // contradictory afterwards
        $guard->fail($r, false);

        $u = $this->usage();
        $this->assertSame(40, $u['global']['tokens']);
        $this->assertSame(1, $u['global']['successes']);
        $this->assertSame(0, $u['global']['failures']);

        $r2 = $guard->reserve(AiSurface::Vendor, 100, false);
        $guard->fail($r2, false);
        $guard->fail($r2, false);
        $guard->settle($r2, 5, null);

        $u = $this->usage();
        $this->assertSame(40, $u['global']['tokens'], 'a second release must not drive the counter below what is really held');
        $this->assertSame(1, $u['global']['failures']);
        $this->assertSame(1, $u['global']['successes']);
    }

    public function test_counters_never_go_negative_when_they_vanish_mid_request(): void
    {
        $guard = app(BudgetGuard::class);
        $r = $guard->reserve(AiSurface::Vendor, 100, false);

        Cache::flush(); // e.g. an operator flushes the cache during the call
        $guard->fail($r, false);

        $this->assertSame(0, $this->usage()['global']['tokens'], 'a release must not conjure a negative counter (= free headroom)');
    }

    public function test_a_successful_answer_is_charged_even_if_settlement_fails_half_way(): void
    {
        $meter = $this->flakyMeter();
        $this->mockProvider()->respondWith('ok', new AiUsage(40, 10));
        // add() calls: 4 for the reservation, then settle: global tokens delta is the 5th.
        $meter->failOnAddNumber = 5;

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the engine to fail closed.');
        } catch (AiConfigurationException) {
        }

        $meter->failOnAddNumber = null;
        $this->assertSame(1, $this->mockProvider()->callCount());
        $this->assertSame($this->estimateOfLastCall(), $this->usage()['global']['tokens'], 'NOT refunded: a possibly-successful call is never released as unused');
        $this->assertSame(1, $this->usage()['global']['requests']);
    }

    public function test_a_failure_while_recording_breaker_success_keeps_the_answer_and_its_accounting(): void
    {
        $this->app->instance(CircuitBreaker::class, new class extends CircuitBreaker
        {
            public function recordSuccess(CircuitPermit $permit): void
            {
                throw new \RuntimeException('cache unavailable');
            }
        });
        $this->mockProvider()->respondWith('ok', new AiUsage(40, 10));

        $result = $this->engine()->run($this->req());

        $this->assertSame('ok', $result->response->text);
        $this->assertSame(50, $this->usage()['global']['tokens']);
        $this->assertSame(1, $this->usage()['global']['successes']);
    }

    public function test_a_failure_recording_the_breaker_failure_does_not_mask_the_provider_error(): void
    {
        $this->app->instance(CircuitBreaker::class, new class extends CircuitBreaker
        {
            public function recordFailure(CircuitPermit $permit): void
            {
                throw new \RuntimeException('cache unavailable');
            }
        });
        $this->mockProvider()->timeoutOnce();

        $e = $this->runAndExpectFailure();

        $this->assertSame(AiProviderException::TIMEOUT, $e->kind);
        $this->assertSame($this->estimateOfLastCall(), $this->usage()['global']['tokens']);
    }

    // ------------------------------------------------ the counter primitive

    public function test_the_counter_primitive_accumulates_and_never_goes_negative_from_nothing(): void
    {
        $meter = new UsageMeter;

        $this->assertSame(5, $meter->add('20990101', 'global', 'requests', 5));
        $this->assertSame(7, $meter->add('20990101', 'global', 'requests', 2));
        $this->assertSame(6, $meter->add('20990101', 'global', 'requests', -1));
        $this->assertSame(0, $meter->add('20990101', 'global', 'never-created', -3), 'decrementing a missing counter creates nothing');
        $this->assertSame(0, $meter->get('20990101', 'global', 'never-created'));
    }

    /** A UsageMeter whose Nth positive add() throws (a cache outage in the middle of a reservation/settlement). */
    private function flakyMeter(): UsageMeter
    {
        $meter = new class extends UsageMeter
        {
            public ?int $failOnAddNumber = null;

            private int $adds = 0;

            public function add(string $day, string $scope, string $metric, int $amount): int
            {
                if ($amount > 0 || $this->failOnAddNumber !== null) {
                    $this->adds++;
                }

                if ($this->failOnAddNumber !== null && $this->adds === $this->failOnAddNumber) {
                    throw new \RuntimeException('cache unavailable');
                }

                return parent::add($day, $scope, $metric, $amount);
            }
        };

        $this->app->instance(UsageMeter::class, $meter);

        return $meter;
    }
}
