<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Exceptions\AiCircuitOpenException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use Illuminate\Support\Facades\Cache;

/**
 * Per-provider circuit breaker on the shared cache (so every PHP process sees the same state).
 *
 *   closed    : requests flow; transient failures are counted in a rolling window.
 *   open      : `failure_threshold` transient failures inside `window_seconds` => every request is refused for
 *               `cooldown_seconds` WITHOUT touching the provider.
 *   half-open : once the cooldown has passed, exactly ONE request (the probe, guarded by a lock) is let through.
 *               Success closes the circuit; failure re-opens it for another cooldown. Everyone else is still refused.
 *
 * Only evidence that the PROVIDER is unhealthy counts (timeouts, 5xx/unavailable, provider rate limits, garbage
 * responses). Our own bad requests and authentication errors prove it answered and do not.
 */
class CircuitBreaker
{
    /** @throws AiCircuitOpenException */
    public function assertAllows(string $provider): CircuitPermit
    {
        // Misconfiguration must fail closed BEFORE the provider is reached, not later on the failure path.
        $this->settings();

        $openUntil = Cache::get($this->key($provider, 'open_until'));

        if ($openUntil === null) {
            return new CircuitPermit($provider);
        }

        $remaining = (int) $openUntil - now()->getTimestamp();

        if ($remaining > 0) {
            throw new AiCircuitOpenException('AI provider circuit is open.', $remaining);
        }

        // Cooldown elapsed: allow exactly one probe through.
        $lock = Cache::lock($this->key($provider, 'probe'), max(60, (int) config('ai.request.deadline_seconds', 40) + 5));

        if (! $lock->get()) {
            throw new AiCircuitOpenException('AI provider circuit is probing.', 5);
        }

        // Another process may have probed, succeeded and closed the circuit between our read and our lock.
        if (Cache::get($this->key($provider, 'open_until')) === null) {
            $lock->release();

            return new CircuitPermit($provider);
        }

        return new CircuitPermit($provider, true, $lock);
    }

    public function recordSuccess(CircuitPermit $permit): void
    {
        Cache::forget($this->key($permit->provider, 'open_until'));
        Cache::forget($this->key($permit->provider, 'failures'));
        $permit->probeLock?->release();
    }

    public function recordFailure(CircuitPermit $permit): void
    {
        [$threshold, $window, $cooldown] = $this->settings();

        if ($permit->isProbe) {
            $this->open($permit->provider, $cooldown);
            $permit->probeLock?->release();

            return;
        }

        $key = $this->key($permit->provider, 'failures');
        Cache::add($key, 0, $window);
        $failures = Cache::increment($key);

        if ($failures === false) {
            // The counter expired between add() and increment(): count this failure in a fresh window.
            Cache::add($key, 0, $window);
            $failures = Cache::increment($key);
        }

        if ((int) $failures >= $threshold) {
            $this->open($permit->provider, $cooldown);
        }
    }

    /** The request never reached the provider (rejected by a later gate): free a probe slot, change nothing else. */
    public function release(CircuitPermit $permit): void
    {
        $permit->probeLock?->release();
    }

    private function open(string $provider, int $cooldown): void
    {
        // Kept long after the cooldown so the half-open state survives until a probe succeeds.
        Cache::put($this->key($provider, 'open_until'), now()->getTimestamp() + $cooldown, $cooldown + 86400);
        Cache::forget($this->key($provider, 'failures'));
    }

    /** @return array{0:int,1:int,2:int} threshold, window seconds, cooldown seconds */
    private function settings(): array
    {
        $t = config('ai.circuit_breaker.failure_threshold');
        $w = config('ai.circuit_breaker.window_seconds');
        $c = config('ai.circuit_breaker.cooldown_seconds');

        if (! is_int($t) || $t < 1 || ! is_int($w) || $w < 1 || ! is_int($c) || $c < 1) {
            throw new AiConfigurationException('AI circuit-breaker settings are not configured correctly.');
        }

        return [$t, $w, $c];
    }

    private function key(string $provider, string $part): string
    {
        return "ai:cb:{$provider}:{$part}";
    }
}
