<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use Illuminate\Support\Facades\Cache;

/**
 * Requests per minute per PRINCIPAL (guest session / vendor), per surface, on the shared cache so it works across
 * PHP processes with no Redis. The principal key is hashed into the cache key so identifiers never appear in cache
 * keys or logs.
 *
 * A fixed, clock-aligned one-minute window with ONE atomic increment per request: the value that increment returns
 * is the request's place in the window, and it is admitted only if that place is within the allowance. Nothing is
 * read, decided in PHP and written back. (Laravel's own RateLimiter is deliberately not used: attempt() checks and
 * then hits, and hit() contains a "reset to 1" fallback that can overwrite a concurrent increment; both let
 * simultaneous processes exceed the limit, which the multi-process test demonstrated on the database cache store.)
 * The cost of a fixed window is that up to twice the allowance can pass across a window boundary.
 */
class AiRateLimiter
{
    private const WINDOW_SECONDS = 60;

    public function attempt(AiRequest $request): void
    {
        $max = config("ai.rate_limits.{$request->surface->value}.per_minute");

        if (! is_int($max) || $max < 1) {
            throw new AiConfigurationException("No valid AI rate limit is configured for the {$request->surface->value} surface.");
        }

        $now = now()->getTimestamp();
        $window = intdiv($now, self::WINDOW_SECONDS);
        $key = 'ai:rate:'.$request->surface->value.':'.hash('sha256', $request->principalKey).':'.$window;

        // Outlives its window, so a counter can never expire mid-window and silently restart from zero.
        Cache::add($key, 0, self::WINDOW_SECONDS * 3);
        $hits = Cache::increment($key);

        if ($hits === false) {
            Cache::add($key, 0, self::WINDOW_SECONDS * 3);
            $hits = Cache::increment($key);
        }

        if ($hits === false) {
            // 'false' must never read as "first request": fail closed.
            throw new AiConfigurationException('The AI rate-limit counter is unavailable.');
        }

        if ((int) $hits > $max) {
            throw new AiRateLimitedException('AI rate limit exceeded.', max(1, ($window + 1) * self::WINDOW_SECONDS - $now));
        }
    }
}
