<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiSurface;

/**
 * A handle on ONE ledger row (`ai_budget_reservations`): what was set aside for one provider attempt. The authoritative
 * state - the amounts currently charged and whether the reservation is still open - lives in the database; this object only
 * names it. The day is fixed at reservation time so settlement hits the same counters across midnight.
 *
 * A reservation holds: one request, an UPPER BOUND of tokens (input bound + the output cap) and, when pricing is
 * configured, the MAXIMUM cost of those tokens. Closing it (settle / fail) is a state transition done under a row lock in
 * {@see BudgetGuard}: the first close wins and every later one - from this object, a copy of it or another process - is a
 * no-op, so a repeated or contradictory bookkeeping call can never apply a correction twice.
 *
 * Every provider attempt (including a retry) has its OWN reservation, so a retry cannot ride on, or hide behind, an
 * earlier attempt's budget.
 */
final class BudgetReservation
{
    public function __construct(
        /** ULID of the ledger row. The adapter of an external provider verifies it is OPEN before it transmits anything. */
        public readonly string $id,
        public readonly string $day,
        public readonly AiSurface $surface,
        /** The upper bound for this ONE provider attempt. */
        public readonly int $estimatedTokens,
        /** The maximum cost of this attempt in micro-USD; null when no complete price is configured. */
        public readonly ?int $estimatedCostMicroUsd = null,
        /** The earlier attempt this one retries, if any. */
        public readonly ?string $retryOf = null,
    ) {}
}
