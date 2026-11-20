<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Data\AiSurface;
use Illuminate\Support\Facades\Cache;

/**
 * The global off switch, in two halves that BOTH must allow a request:
 *   1. config('ai.enabled')  — off by default; changing it needs a config change/redeploy;
 *   2. a runtime flag in the shared cache (`php artisan ai:kill-switch engage|release`) — flips instantly,
 *      survives restarts (database cache store) and needs no redeploy or config re-cache.
 *
 * Fails CLOSED: if the cache cannot be read, the engine is treated as switched off.
 */
class KillSwitch
{
    public const CACHE_KEY = 'ai:kill-switch';

    public function engaged(): bool
    {
        try {
            return (bool) Cache::get(self::CACHE_KEY, false);
        } catch (\Throwable) {
            return true;
        }
    }

    public function engage(): void
    {
        Cache::forever(self::CACHE_KEY, true);
    }

    public function release(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function engineEnabled(): bool
    {
        return config('ai.enabled') === true && ! $this->engaged();
    }

    public function surfaceEnabled(AiSurface $surface): bool
    {
        return $this->engineEnabled() && config("ai.surfaces.{$surface->value}.enabled") === true;
    }
}
