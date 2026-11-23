<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiResult;
use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiDisabledException;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiInvalidRequestException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Logging\AiLogger;
use App\Services\Ai\Providers\AiProviderManager;
use App\Services\Ai\Safety\AiRateLimiter;
use App\Services\Ai\Safety\CircuitBreaker;
use App\Services\Ai\Safety\CircuitPermit;
use App\Services\Ai\Safety\ConcurrencyGuard;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\BudgetReservation;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\TokenEstimator;

/**
 * The single entry point to the AI engine: one synchronous, bounded provider turn.
 *
 * Gate order (cheapest and most protective first; nothing after a failed gate runs, and the provider is
 * never reached by a request that failed any of them):
 *
 *   1. kill switch + surface switch   -> AiDisabledException
 *   2. provider resolution + config   -> AiConfigurationException        (no network)
 *   3. request validation + clamping  -> AiInvalidRequestException
 *   4. per-principal rate limit       -> AiRateLimitedException
 *   5. circuit breaker                -> AiCircuitOpenException
 *   6. shared concurrency slot        -> AiBusyException
 *   7. hard budget reservation        -> AiBudgetExceededException / AiConfigurationException
 *      (requests + token UPPER BOUND + maximum cost, global AND surface, atomically; see BudgetGuard)
 *   8. provider call (bounded timeout, bounded retries, turn deadline)
 *
 * It has NO business knowledge: no tools, no catalog, no orders, no wallets, no payment code. In M5.2 any tool
 * calls the model returns are carried back untouched and never executed.
 */
class AiOrchestrator
{
    /** Hard ceiling on retries whatever the config says. */
    private const MAX_RETRIES_CEILING = 2;

    public function __construct(
        private readonly KillSwitch $killSwitch,
        private readonly AiProviderManager $providers,
        private readonly AiRateLimiter $rateLimiter,
        private readonly CircuitBreaker $breaker,
        private readonly ConcurrencyGuard $concurrency,
        private readonly BudgetGuard $budget,
        private readonly TokenEstimator $estimator,
        private readonly PriceTable $prices,
        private readonly AiLogger $logger,
    ) {}

    /** @throws AiException every failure is an AiException with a safe userMessage() */
    public function run(AiRequest $request): AiResult
    {
        $started = microtime(true);
        $provider = null;
        $permit = null;
        $slot = null;
        $reservation = null;
        $attempts = 0;
        $model = null;
        $providerCalled = false;
        $estimate = 0;

        try {
            if (! $this->killSwitch->surfaceEnabled($request->surface)) {
                throw new AiDisabledException('The AI engine or this surface is disabled.');
            }

            $provider = $this->providers->provider();
            $model = $this->resolveModel($request, $provider);
            $provider->assertConfigured($model);

            $resolved = $this->resolve($request, $model, $provider);

            $this->rateLimiter->attempt($resolved);
            $permit = $this->breaker->assertAllows($provider->name());
            $slot = $this->concurrency->acquire();

            // Reserve the MOST this attempt can consume: input upper bound + the output cap that is sent to the provider,
            // and the cost of exactly those tokens. The provider is not reached unless all of it fits every budget.
            $inputBound = $this->estimator->inputUpperBound($resolved);
            $outputBound = $this->estimator->outputUpperBound($resolved);
            $estimate = $inputBound + $outputBound;
            $maxCost = $this->prices->maxCostMicroUsd($provider->name(), (string) $model, $inputBound, $outputBound);
            $reservation = $this->budget->reserve($resolved->surface, $estimate, $provider->isLive(), $maxCost);

            // The emergency stop is checked again AFTER the (possibly slow) reservation and immediately BEFORE the provider is
            // reached, so a shutdown that happened in between stops this request too. Nothing was sent: the reservation is
            // released by the AiException branch below.
            if (! $this->killSwitch->surfaceEnabled($resolved->surface)) {
                throw new AiDisabledException('The AI engine or this surface is disabled.');
            }

            // From here on the provider MAY have been reached, so the reservation is never released as "unused"
            // except by BudgetGuard::fail() for an attempt that provably was not processed.
            $providerCalled = true;
            $surface = $resolved->surface;
            $response = $this->callProvider($provider, $resolved, $attempts, $reservation, function (AiProviderException $failed, BudgetReservation $previous) use ($surface, $estimate, $maxCost, $provider): BudgetReservation {
                // A retry is a second provider request with its OWN durable reservation, taken through the same atomic budget
                // check. The previous attempt is closed in the same transaction (its reservation stands if it may have been
                // processed, and is released if it provably was not). If the retry cannot be reserved, nothing changes,
                // callProvider keeps the original provider error and no retry is sent.
                if (! $this->killSwitch->surfaceEnabled($surface)) {
                    throw new AiDisabledException('The AI engine or this surface is disabled.');
                }

                return $this->budget->reserve($surface, $estimate, $provider->isLive(), $maxCost, $previous, $failed->mayHaveConsumedTokens());
            });

            // Accounting first, while the reservation is still ours to settle. Known usage replaces the reservation (tokens
            // and cost); unknown usage - or a model with no price - leaves the FULL reservation charged. At-most-once.
            $cost = $this->prices->costMicroUsd($response->provider, $response->model, $response->usage);
            $this->budget->settle($reservation, $response->usage->totalTokens(), $cost);

            $this->recordBreakerSuccess($permit);
            $permit = null;

            $duration = $this->elapsedMs($started);
            $this->logger->completed($resolved, $response->provider, $response->model, $response->usage->inputTokens, $response->usage->outputTokens, $duration, $attempts, $cost);

            return new AiResult($resolved->requestId, $response, $duration, $attempts, $cost);
        } catch (AiProviderException $e) {
            // The provider was called and failed. Budget first (the part that must not be lost), then the breaker;
            // neither may mask the provider error.
            if ($reservation !== null) {
                try {
                    // Released only if the attempt provably was not processed; a timeout / 5xx / garbage reply keeps the
                    // whole reservation (tokens and maximum cost) charged.
                    $this->budget->fail($reservation, $e->mayHaveConsumedTokens());
                } catch (\Throwable) {
                    // Accounting could not complete: whatever is still charged stays charged (conservative).
                }
            }

            // Only evidence of an unhealthy provider opens the circuit.
            if ($permit) {
                try {
                    $e->isTransient() ? $this->breaker->recordFailure($permit) : $this->breaker->recordSuccess($permit);
                    $permit = null;
                } catch (\Throwable) {
                    // The finally block still frees a probe lock; the open/closed state is best effort here.
                }
            }

            $this->logger->failed($request, $provider?->name() ?? 'unknown', $model, $e, $this->elapsedMs($started), $attempts);

            throw $e;
        } catch (AiException $e) {
            // Refused before (or instead of) a provider call.
            $this->logger->rejected($request, $e, $provider?->name());

            // A reservation taken but never used (the emergency stop engaged in between) provably was not processed.
            if (! $providerCalled && $reservation !== null) {
                try {
                    $this->budget->fail($reservation, false);
                } catch (\Throwable) {
                    // It stays charged (conservative).
                }
            }

            throw $e;
        } catch (\Throwable $e) {
            // Anything unexpected (cache outage, bug…) fails CLOSED with a generic error. Only the class is logged:
            // the message of a foreign exception may contain request data or credentials.
            $this->logger->failed($request, $provider?->name() ?? 'unknown', $model, $e, $this->elapsedMs($started), $attempts);

            // If the provider may have been reached (e.g. it answered and the bookkeeping afterwards failed), the
            // reservation is deliberately LEFT CHARGED: a possibly-successful call is never refunded as unused.
            // If it provably was not reached, nothing was processed and the tokens go back.
            if (! $providerCalled && $reservation !== null) {
                try {
                    $this->budget->fail($reservation, false);
                } catch (\Throwable) {
                }
            }

            throw new AiConfigurationException('Unexpected AI engine failure ('.$e::class.').');
        } finally {
            // Always give back what we hold, whichever way we left. (The budget is settled, failed or deliberately
            // retained on every path above, so only the permit and slot remain.)
            if ($permit instanceof CircuitPermit) {
                try {
                    $this->breaker->release($permit);
                } catch (\Throwable) {
                    // The probe lock's TTL frees it regardless.
                }
            }
            if ($slot) {
                try {
                    $slot->release();
                } catch (\Throwable) {
                    // The lock TTL frees the slot regardless.
                }
            }
        }
    }

    private function recordBreakerSuccess(?CircuitPermit $permit): void
    {
        if ($permit === null) {
            return;
        }

        try {
            $this->breaker->recordSuccess($permit);
        } catch (\Throwable) {
            // The provider answered; losing the "closed" bookkeeping must not discard the response or its accounting.
        }
    }

    private function resolveModel(AiRequest $request, AiProvider $provider): ?string
    {
        $model = $request->model ?: config('ai.default_model');

        if (! is_string($model) || $model === '') {
            // Only the offline provider may supply its own placeholder; a live provider must be told which model.
            $model = $provider->isLive() ? null : config("ai.providers.{$provider->name()}.model");
        }

        if ($provider->isLive() && (! is_string($model) || $model === '')) {
            throw new AiConfigurationException('No AI model is configured.');
        }

        return is_string($model) && $model !== '' ? $model : null;
    }

    /** Validate size/context and clamp output tokens and timeout to the configured ceilings. */
    private function resolve(AiRequest $request, ?string $model, AiProvider $provider): AiRequest
    {
        $cfg = (array) config('ai.request', []);

        foreach (['max_request_bytes', 'max_context_messages', 'max_output_tokens', 'timeout_seconds', 'max_timeout_seconds', 'deadline_seconds'] as $k) {
            if (! isset($cfg[$k]) || ! is_int($cfg[$k]) || $cfg[$k] < 1) {
                throw new AiConfigurationException("Invalid AI request setting [{$k}].");
            }
        }

        if (count($request->messages) > $cfg['max_context_messages']) {
            throw new AiInvalidRequestException('The conversation context is too long.');
        }

        if ($request->payloadBytes() > $cfg['max_request_bytes']) {
            throw new AiInvalidRequestException('The request is too large.');
        }

        if ($provider->isLive()) {
            // Timeouts must nest: one attempt <= the most an attempt may be given <= one orchestrator run <= the turn.
            $turn = config('ai.request.turn_deadline_seconds');

            if ($cfg['timeout_seconds'] > $cfg['max_timeout_seconds']
                || $cfg['max_timeout_seconds'] > $cfg['deadline_seconds']
                || ! is_int($turn) || $turn < $cfg['deadline_seconds']) {
                throw new AiConfigurationException('The AI timeouts do not fit inside the request deadlines.');
            }
        }

        // The whole assistant turn has a deadline; refuse to START a provider round that has (almost) no time left.
        if ($request->deadlineAt !== null && $request->deadlineAt - microtime(true) < 2) {
            throw new AiBusyException('The time allowed for this request has been used.', 1);
        }

        $maxOut = min($request->maxOutputTokens ?? $cfg['max_output_tokens'], $cfg['max_output_tokens']);
        $timeout = min($request->timeoutSeconds ?? $cfg['timeout_seconds'], $cfg['max_timeout_seconds']);

        if ($maxOut < 1 || $timeout < 1) {
            throw new AiInvalidRequestException('Invalid output or timeout limits.');
        }

        return $request->resolved($model ?? '', $maxOut, $timeout);
    }

    /**
     * At most 1 + min(max_retries, 2) attempts, only for timeouts / provider-unavailable, and only while a full
     * further attempt still fits inside the turn deadline. No sleeping: a synchronous request must not idle.
     */
    private function callProvider(AiProvider $provider, AiRequest $resolved, int &$attempts, BudgetReservation &$reservation, \Closure $beforeRetry): AiResponse
    {
        $maxAttempts = 1 + min(self::MAX_RETRIES_CEILING, max(0, (int) config('ai.request.max_retries', 0)));
        $deadline = microtime(true) + (int) config('ai.request.deadline_seconds');
        if ($resolved->deadlineAt !== null) {
            $deadline = min($deadline, $resolved->deadlineAt);
        }

        for ($attempt = 1; ; $attempt++) {
            $attempts = $attempt;

            // Never let one attempt run past the turn deadline.
            $remaining = (int) floor($deadline - microtime(true));
            $timeout = max(1, min((int) $resolved->timeoutSeconds, $remaining));
            $request = ($timeout === $resolved->timeoutSeconds
                ? $resolved
                : $resolved->resolved((string) $resolved->model, (int) $resolved->maxOutputTokens, $timeout)
            )->withReservation($reservation->id); // every attempt names the reservation that pays for it

            try {
                return $provider->send($request);
            } catch (AiProviderException $e) {
                // A live provider has no idempotency key: an attempt that MAY have been processed is never repeated (it could
                // be billed twice). Only a failure that provably never left this server may be retried.
                $safeToRepeat = ! $provider->isLive() || ! $e->mayHaveConsumedTokens();

                $canRetry = $e->isRetryable()
                    && $safeToRepeat
                    && $attempt < $maxAttempts
                    && ($deadline - microtime(true)) >= $resolved->timeoutSeconds;

                if (! $canRetry) {
                    throw $e;
                }

                try {
                    $reservation = $beforeRetry($e, $reservation);
                } catch (\Throwable) {
                    // No budget (or no accounting, or the emergency stop) for another provider request: stop with the original
                    // error. The previous reservation is untouched and is closed by the caller.
                    throw $e;
                }
            } catch (\Throwable) {
                // A foreign exception from an adapter: replace it (its message/trace may hold headers or keys).
                throw AiProviderException::unexpected();
            }
        }
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
