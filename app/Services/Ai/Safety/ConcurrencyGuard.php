<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Caps provider calls in flight ACROSS ALL PHP PROCESSES, so AI traffic cannot occupy every worker and slow
 * checkout. Implemented as N named cache locks ("slots"); a request takes the first free slot and releases it
 * when done. Locks carry a TTL, so a crashed process cannot hold a slot forever. No process-local state, no
 * Redis, no queue worker: it works on the database cache store used in production.
 */
class ConcurrencyGuard
{
    /** @throws AiBusyException when every slot is taken */
    public function acquire(): Lock
    {
        $max = config('ai.concurrency.max_in_flight');
        $seconds = config('ai.concurrency.lock_seconds');

        if (! is_int($max) || $max < 1 || ! is_int($seconds) || $seconds < 5) {
            throw new AiConfigurationException('AI concurrency limits are not configured correctly.');
        }

        // A slot must outlive the longest possible turn, otherwise it could expire mid-call and be double-booked.
        $seconds = max($seconds, (int) config('ai.request.deadline_seconds', 40) + 5);

        for ($i = 0; $i < $max; $i++) {
            $lock = Cache::lock("ai:slot:{$i}", $seconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new AiBusyException('All AI concurrency slots are in use.', 5);
    }
}
