<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiSurface;

/**
 * What was set aside for one request. The day is fixed here so settlement hits the same counters across midnight.
 *
 * A reservation is SETTLED OR FAILED AT MOST ONCE: the first call to {@see close()} wins and every later settle/fail
 * on the same object is a no-op, so a retry of the bookkeeping (or a double code path) can never apply a correction
 * twice. The state flips BEFORE the counters are touched, so if the cache dies half-way through settlement the
 * remainder is simply not applied, which leaves the pessimistic estimate in place (the safe direction).
 */
final class BudgetReservation
{
    private bool $closed = false;

    /** Tokens currently held for this request: one estimate per provider attempt that may have been processed. */
    private int $heldTokens;

    public function __construct(
        public readonly string $day,
        public readonly AiSurface $surface,
        /** The pessimistic estimate for ONE provider attempt. */
        public readonly int $estimatedTokens,
    ) {
        $this->heldTokens = $estimatedTokens;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return bool true for the caller that closed it, false if it was already closed */
    public function close(): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->closed = true;

        return true;
    }

    public function heldTokens(): int
    {
        return $this->heldTokens;
    }

    /** A further provider attempt was admitted: its estimate is held on top of the earlier attempts'. */
    public function addAttempt(int $tokens): void
    {
        $this->heldTokens += $tokens;
    }
}
