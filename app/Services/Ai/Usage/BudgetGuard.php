<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiConfigurationException;

/**
 * Hard daily budgets, enforced BEFORE the provider is called.
 *
 * Mechanism: reserve first (atomic increment on the shared cache), compare to the limit, and roll the increments
 * back if any limit would be exceeded. Two processes racing for the last tokens cannot both win.
 *
 * Rules:
 *  - `null` limit = not configured; `0` = hard block; a positive int = the cap. Garbage is a configuration error.
 *  - For a LIVE provider the global AND the per-surface daily TOKEN limits are mandatory. "Not configured" is
 *    never silently unlimited for something that spends money: the request is refused (ai_misconfigured).
 *    The offline mock may run without limits (local dev/tests) but is counted all the same.
 *  - Requests and tokens are reserved up front (tokens as an estimate, then settled to the provider's reported
 *    usage). The cost limit is checked before the call against the cost already recorded, so the last in-flight
 *    request(s) can overshoot it slightly; token and request limits cannot overshoot by concurrency.
 *
 * Settlement rules (what happens to the reservation once the provider has - or may have - been reached):
 *  - success, usage known     : the estimate is replaced by the reported usage (only the unused part is released);
 *  - success, usage unknown   : the estimate STANDS (never released as if it were zero);
 *  - failure that may have been processed (timeout, unavailable after sending, invalid response): the estimate
 *    STANDS - a request that may have reached the provider is never assumed to have cost nothing;
 *  - failure that provably was not processed (rate-limited, authentication, bad request, or an adapter that says
 *    nothing was sent): the tokens are released, but the attempt still counts as a request;
 *  - a retry is a new provider request: it reserves again through the same atomic limit check, and the earlier
 *    attempt's estimate stays charged.
 * Settlement and failure are at-most-once per reservation (see BudgetReservation::close()).
 */
class BudgetGuard
{
    public function __construct(private readonly UsageMeter $meter) {}

    /**
     * @throws AiBudgetExceededException
     * @throws AiConfigurationException
     */
    public function reserve(AiSurface $surface, int $estimatedTokens, bool $live): BudgetReservation
    {
        $scopes = $this->scopeLimits($surface);

        if ($live) {
            foreach ($scopes as $scope => $limits) {
                if ($limits['daily_tokens'] === null) {
                    throw new AiConfigurationException("No daily token budget is configured for scope [{$scope}]; a live AI provider will not run without one.");
                }
            }
        }

        $day = $this->meter->today();

        $this->assertCostBudgetsOpen($day, $scopes);
        $this->apply($day, $scopes, ['requests' => 1, 'tokens' => $estimatedTokens]);

        return new BudgetReservation($day, $surface, $estimatedTokens);
    }

    /**
     * A retry is another provider request. Reserve one more attempt through the same atomic limit check; on
     * refusal nothing is changed and the caller must not send the retry. The previous attempt stays charged.
     *
     * If the attempt that just failed provably was NOT processed, its token reservation is simply reused (the retry
     * still counts as a request). Otherwise that estimate stays charged and a fresh one is reserved.
     *
     * @param  ?int  $previousAttemptCostMicroUsd  conservative cost of the attempt that just failed (ambiguously)
     *
     * @throws AiBudgetExceededException
     */
    public function extend(BudgetReservation $r, int $tokens, bool $previousMayHaveBeenProcessed = true, ?int $previousAttemptCostMicroUsd = null): void
    {
        if ($r->isClosed()) {
            throw new AiBudgetExceededException('The budget reservation is already closed.');
        }

        $scopes = $this->scopeLimits($r->surface);

        $this->assertCostBudgetsOpen($r->day, $scopes);
        $this->apply($r->day, $scopes, $previousMayHaveBeenProcessed ? ['requests' => 1, 'tokens' => $tokens] : ['requests' => 1]);

        if ($previousMayHaveBeenProcessed) {
            $r->addAttempt($tokens);
        }

        if ($previousMayHaveBeenProcessed && $previousAttemptCostMicroUsd !== null && $previousAttemptCostMicroUsd > 0) {
            foreach ($this->scopesOf($r) as $scope) {
                $this->meter->add($r->day, $scope, 'cost_micro_usd', $previousAttemptCostMicroUsd);
            }
        }
    }

    /**
     * Success. The final attempt's estimate is replaced by the reported usage when known (a lower figure releases
     * only the unused part; a higher one is recorded in full). Unknown usage leaves the estimate in place.
     * Earlier ambiguous attempts (retries) stay charged.
     */
    public function settle(BudgetReservation $r, ?int $actualTokens, ?int $costMicroUsd): void
    {
        if (! $r->close()) {
            return;
        }

        foreach ($this->scopesOf($r) as $scope) {
            if ($actualTokens !== null && $actualTokens !== $r->estimatedTokens) {
                $this->meter->add($r->day, $scope, 'tokens', $actualTokens - $r->estimatedTokens);
            }

            if ($costMicroUsd !== null && $costMicroUsd > 0) {
                $this->meter->add($r->day, $scope, 'cost_micro_usd', $costMicroUsd);
            }

            $this->meter->add($r->day, $scope, 'successes', 1);
        }
    }

    /**
     * Failure of the (final) provider attempt.
     *
     * The attempt always counts as a request (a failing provider cannot be hammered past the request cap).
     * Tokens are released ONLY when the attempt provably was not processed; otherwise the estimate stays charged,
     * together with a conservative cost when pricing is configured.
     */
    public function fail(BudgetReservation $r, bool $mayHaveBeenProcessed, ?int $conservativeCostMicroUsd = null): void
    {
        if (! $r->close()) {
            return;
        }

        foreach ($this->scopesOf($r) as $scope) {
            if ($mayHaveBeenProcessed) {
                if ($conservativeCostMicroUsd !== null && $conservativeCostMicroUsd > 0) {
                    $this->meter->add($r->day, $scope, 'cost_micro_usd', $conservativeCostMicroUsd);
                }
            } else {
                $this->meter->add($r->day, $scope, 'tokens', -$r->estimatedTokens);
            }

            $this->meter->add($r->day, $scope, 'failures', 1);
        }
    }

    /**
     * Atomically add `$amounts` to every scope, enforcing each scope's limit. If ANY limit would be exceeded - or
     * the cache fails part-way - every increment already made is rolled back before the exception leaves, so a
     * partial reservation (e.g. global fine, surface refused) never lingers.
     *
     * @param  array<string,array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int}>  $scopes
     * @param  array<string,int>  $amounts  metric => amount
     */
    private function apply(string $day, array $scopes, array $amounts): void
    {
        $applied = [];

        try {
            foreach ($scopes as $scope => $limits) {
                foreach ($amounts as $metric => $amount) {
                    $new = $this->meter->add($day, $scope, $metric, $amount);
                    $applied[] = [$scope, $metric, $amount];
                    $limit = $limits['daily_'.$metric];

                    if ($limit !== null && $new > $limit) {
                        throw new AiBudgetExceededException("Daily {$metric} budget exhausted for scope [{$scope}].");
                    }
                }
            }
        } catch (\Throwable $e) {
            foreach ($applied as [$s, $m, $a]) {
                try {
                    $this->meter->add($day, $s, $m, -$a);
                } catch (\Throwable) {
                    // Best effort: a rollback that cannot run leaves the counter HIGH (conservative), never low.
                }
            }

            throw $e;
        }
    }

    /** @param array<string,array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int}> $scopes */
    private function assertCostBudgetsOpen(string $day, array $scopes): void
    {
        foreach ($scopes as $scope => $limits) {
            if ($limits['daily_cost_micro_usd'] !== null && $this->meter->get($day, $scope, 'cost_micro_usd') >= $limits['daily_cost_micro_usd']) {
                throw new AiBudgetExceededException("Daily cost budget exhausted for scope [{$scope}].");
            }
        }
    }

    /** @return array<string,array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int}> */
    private function scopeLimits(AiSurface $surface): array
    {
        return [
            'global' => $this->limits('ai.limits.global'),
            UsageMeter::surfaceScope($surface) => $this->limits("ai.limits.surfaces.{$surface->value}"),
        ];
    }

    /** @return list<string> */
    private function scopesOf(BudgetReservation $r): array
    {
        return ['global', UsageMeter::surfaceScope($r->surface)];
    }

    /** @return array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int} */
    private function limits(string $path): array
    {
        $raw = (array) config($path, []);
        $out = [];

        foreach (['daily_requests', 'daily_tokens', 'daily_cost_micro_usd'] as $k) {
            $v = $raw[$k] ?? null;

            if ($v !== null && (! is_int($v) || $v < 0)) {
                throw new AiConfigurationException("Invalid AI budget value for [{$path}.{$k}].");
            }

            $out[$k] = $v;
        }

        return $out;
    }
}
