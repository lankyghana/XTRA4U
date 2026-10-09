<?php

namespace App\Services\UtilityBills;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * XTRA4U's own per-endpoint budget for KiNG FLEXY calls, shared by every web
 * request, queue worker and scheduler process (it lives in the shared cache:
 * the database store in production), and enforced as a SLIDING 60-second
 * window: no rolling minute ever carries more than the configured number of
 * calls. A fixed window (Laravel's RateLimiter) can let two full budgets out
 * within seconds across a window boundary, which is exactly what earns a 429
 * from a provider counting per rolling minute.
 *
 * Every endpoint caller goes through acquire() in the provider client, so
 * normal submissions, sweeper retries, admin retries and admin status refreshes
 * all draw on the same budget; nothing can bypass it.
 *
 * The log of recent call times is read and written under one shared cache lock,
 * so two processes can never both take the last slot. If the lock cannot be had
 * quickly the call is refused (treated as rate-limited), never sent unaccounted.
 */
class ProviderRateBudget
{
    public const WINDOW = 60;

    public const ENDPOINTS = ['billers', 'lookup', 'pay', 'status'];

    /** Configured calls per rolling minute; 0 disables the endpoint. */
    public function limit(string $endpoint): int
    {
        return max(0, (int) config('utility_bills.rate.'.$endpoint.'_per_minute', 0));
    }

    /**
     * Atomically take one slot for a call about to be sent.
     *
     * @return int 0 when granted (and recorded); otherwise seconds until a slot frees
     */
    public function acquire(string $endpoint): int
    {
        $limit = $this->limit($endpoint);
        if ($limit < 1) {
            return self::WINDOW;
        }

        try {
            return Cache::lock($this->lockKey($endpoint), 10)->block(5, function () use ($endpoint, $limit) {
                $now = $this->now();
                $log = $this->recent($endpoint, $now);

                if (count($log) >= $limit) {
                    return $this->secondsUntilFree($log, $limit, $now);
                }

                $log[] = $now;
                Cache::put($this->logKey($endpoint), $log, self::WINDOW + 5);

                return 0;
            });
        } catch (LockTimeoutException) {
            return 1;
        }
    }

    /** Read-only: seconds until acquire() could succeed (0 = a slot is free now). */
    public function availableIn(string $endpoint): int
    {
        $limit = $this->limit($endpoint);
        if ($limit < 1) {
            return self::WINDOW;
        }

        $now = $this->now();
        $log = $this->recent($endpoint, $now);

        return count($log) < $limit ? 0 : $this->secondsUntilFree($log, $limit, $now);
    }

    /** Calls recorded in the current rolling minute. */
    public function used(string $endpoint): int
    {
        return count($this->recent($endpoint, $this->now()));
    }

    public function clear(string $endpoint): void
    {
        Cache::forget($this->logKey($endpoint));
    }

    /** @return list<float> ascending call times inside the window */
    private function recent(string $endpoint, float $now): array
    {
        $log = Cache::get($this->logKey($endpoint));
        $log = is_array($log) ? array_values(array_filter($log, fn ($t) => is_numeric($t) && (float) $t > $now - self::WINDOW)) : [];
        sort($log);

        return $log;
    }

    /** When enough of the oldest calls have aged out for one more to fit. */
    private function secondsUntilFree(array $log, int $limit, float $now): int
    {
        $mustExpire = $log[count($log) - $limit];

        return max(1, (int) ceil($mustExpire + self::WINDOW - $now));
    }

    private function now(): float
    {
        return now()->getPreciseTimestamp(6) / 1_000_000;
    }

    private function logKey(string $endpoint): string
    {
        return 'utility_bills.budget.'.$endpoint;
    }

    private function lockKey(string $endpoint): string
    {
        return 'utility_bills.budget.'.$endpoint.'.lock';
    }
}
