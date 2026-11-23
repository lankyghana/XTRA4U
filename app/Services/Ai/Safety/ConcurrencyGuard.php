<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Caps AI work in flight ACROSS ALL PHP PROCESSES, so AI traffic cannot occupy every worker and slow checkout.
 * Implemented as N named cache locks ("slots"); a request takes the first free slot and releases it when done. Locks
 * carry a TTL, so a crashed process cannot hold a slot forever. No process-local state, no Redis, no queue worker: it
 * works on the database cache store used in production. A request that finds every slot taken is refused IMMEDIATELY
 * (it never waits), so a refused request does not hold a worker either.
 *
 * Two scopes:
 *  - a single orchestrator run (one provider round) takes a slot by itself, as before;
 *  - an assistant TURN (round 1 + tools + round 2) takes ONE slot for its whole length via {@see turn()}, and the
 *    orchestrator runs inside it without taking another. So the worker time one visitor can hold is a single slot for
 *    at most `turn_deadline_seconds`, including the time spent in tools between the two rounds.
 */
class ConcurrencyGuard
{
    private bool $turnHeld = false;

    /** @throws AiBusyException when every slot is taken */
    public function acquire(): ConcurrencySlot
    {
        if ($this->turnHeld) {
            return ConcurrencySlot::borrowed();
        }

        return ConcurrencySlot::owning($this->lock());
    }

    /**
     * Run `$callback` (a whole assistant turn) while holding one slot.
     *
     * @template T
     *
     * @param  \Closure():T  $callback
     * @return T
     *
     * @throws AiBusyException
     */
    public function turn(\Closure $callback): mixed
    {
        if ($this->turnHeld) {
            return $callback();
        }

        $slot = ConcurrencySlot::owning($this->lock());
        $this->turnHeld = true;

        try {
            return $callback();
        } finally {
            $this->turnHeld = false;

            try {
                $slot->release();
            } catch (\Throwable) {
                // The lock TTL frees the slot regardless.
            }
        }
    }

    private function lock(): Lock
    {
        $max = config('ai.concurrency.max_in_flight');
        $seconds = config('ai.concurrency.lock_seconds');

        if (! is_int($max) || $max < 1 || ! is_int($seconds) || $seconds < 5) {
            throw new AiConfigurationException('AI concurrency limits are not configured correctly.');
        }

        // A slot must outlive the longest possible turn, otherwise it could expire mid-call and be double-booked.
        $seconds = max($seconds, (int) config('ai.request.deadline_seconds', 40) + 5, (int) config('ai.request.turn_deadline_seconds', 0) + 5);

        for ($i = 0; $i < $max; $i++) {
            $lock = Cache::lock("ai:slot:{$i}", $seconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new AiBusyException('All AI concurrency slots are in use.', 5);
    }
}
