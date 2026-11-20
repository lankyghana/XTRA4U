<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiSurface;
use Illuminate\Support\Facades\Cache;

/**
 * Daily usage counters on the shared cache. COUNTS ONLY: no prompt, response, identity or message text is ever
 * stored here. Per-request detail (provider, model, surface, request id, tokens, duration, outcome) goes to the
 * redacted structured log instead; persistent transcript/usage tables are deliberately not part of M5.2.
 *
 * Keys: ai:usage:{YYYYMMDD}:{scope}:{metric}   scope = global | surface.<name>
 * Metrics: requests, tokens, cost_micro_usd, successes, failures.
 * They expire on their own after a few days; the day is decided once per request (see BudgetReservation).
 *
 * Every change is a single atomic increment/decrement on the shared store (a row-locked UPDATE on the database
 * cache store). Nothing here reads a value, computes in PHP and writes it back.
 */
class UsageMeter
{
    public const METRICS = ['requests', 'tokens', 'cost_micro_usd', 'successes', 'failures'];

    private const TTL_SECONDS = 3 * 86400;

    public function today(): string
    {
        return now()->format('Ymd');
    }

    public static function surfaceScope(AiSurface $surface): string
    {
        return 'surface.'.$surface->value;
    }

    /**
     * Atomically add (or subtract, when negative) and return the new value.
     *
     * @throws \RuntimeException when the counter cannot be updated: callers must fail closed, never treat that as 0
     */
    public function add(string $day, string $scope, string $metric, int $amount): int
    {
        $key = $this->key($day, $scope, $metric);

        if ($amount === 0) {
            return $this->get($day, $scope, $metric);
        }

        if ($amount < 0) {
            // Never conjure a counter just to drive it negative (e.g. it expired or the cache was flushed
            // mid-request): a negative counter would hand out headroom that was never there.
            if (Cache::get($key) === null) {
                return 0;
            }

            $new = Cache::decrement($key, -$amount);

            return $new === false ? 0 : (int) $new;
        }

        Cache::add($key, 0, self::TTL_SECONDS);
        $new = Cache::increment($key, $amount);

        if ($new === false) {
            // The counter vanished between add() and increment() (expiry boundary / flush): recreate it once.
            Cache::add($key, 0, self::TTL_SECONDS);
            $new = Cache::increment($key, $amount);
        }

        if ($new === false) {
            // 'false' must never be read as "0 used so far", which would pass every limit check.
            throw new \RuntimeException('AI usage counter could not be updated.');
        }

        return (int) $new;
    }

    public function get(string $day, string $scope, string $metric): int
    {
        return (int) Cache::get($this->key($day, $scope, $metric), 0);
    }

    /**
     * Everything counted for a day. Foundation for the later admin/cost reports.
     *
     * @return array<string,array<string,int>>
     */
    public function snapshot(?string $day = null): array
    {
        $day ??= $this->today();
        $scopes = ['global'];
        foreach (AiSurface::cases() as $surface) {
            $scopes[] = self::surfaceScope($surface);
        }

        $out = [];
        foreach ($scopes as $scope) {
            foreach (self::METRICS as $metric) {
                $out[$scope][$metric] = $this->get($day, $scope, $metric);
            }
        }

        return $out;
    }

    private function key(string $day, string $scope, string $metric): string
    {
        return "ai:usage:{$day}:{$scope}:{$metric}";
    }
}
