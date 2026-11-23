<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Integrity\IntegrityGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hard daily budgets, enforced BEFORE the provider is called, against an UPPER BOUND of what the call can cost, on a
 * DURABLE ledger (M5.7.1).
 *
 * Storage. Authoritative state is two database tables, never the Laravel cache (so `cache:clear`, an eviction or a restart
 * cannot reset a budget):
 *   - `ai_usage_counters`      one row per (day, scope): requests / tokens / cost_micro_usd charged so far;
 *   - `ai_budget_reservations` the ledger: one row per provider attempt, with what it currently charges and its state.
 * The counters always equal the sum of the ledger rows. Both are changed only here, in one transaction.
 *
 * Reservation. In a transaction the global and the surface counter rows are locked (always in the same order: global,
 * then surface, so two processes cannot deadlock on each other), the counters are verified against the ledger, and a
 * CONDITIONAL atomic UPDATE (`SET x = x + n WHERE x + n <= limit`) takes the budget; no affected row = refused. The ledger
 * row is inserted in the same transaction, so a reservation is all-or-nothing: a refusal on the surface scope leaves nothing
 * behind on the global scope, a crash mid-way rolls everything back, and two processes racing for the last tokens cannot both
 * win. What is reserved is the MAXIMUM the attempt can consume:
 *   - requests : 1
 *   - tokens   : input upper bound (TokenEstimator) + the output cap that the adapter sends as the provider's hard limit
 *   - cost     : those tokens at the configured model prices (PriceTable::maxCostMicroUsd, rounded up)
 * on the global scope AND the surface scope.
 *
 * Rules:
 *  - `null` limit = not configured; `0` = hard block; a positive int = the cap. Garbage is a configuration error.
 *  - For a LIVE provider requests, tokens AND cost limits must all be explicit integers on BOTH scopes and the model's
 *    pricing must be configured; "not configured" is never silently unlimited for something that spends money. The offline
 *    mock may run without limits (local dev/tests) and is counted all the same. A live provider also needs an unflagged day.
 *  - FAIL CLOSED. A database that cannot be reached or written, a counter row that is missing, altered or inconsistent with
 *    the ledger, a negative counter: the request is refused (AiConfigurationException) and the provider is not called. A
 *    missing or damaged record is never read as "0 used".
 *
 * Settlement (what happens to the reservation once the provider has - or may have - been reached). Closing a reservation
 * is a state transition under a row lock, so it happens AT MOST ONCE however often, and from however many processes, it is
 * attempted:
 *  - success, usage known     -> `settled`: the reservation is replaced by the reported usage and its cost (only the unused
 *                               part is released). Usage ABOVE the reservation (the bound's assumption failed) is recorded in
 *                               full, logged, and flags the day so live execution stops;
 *  - success, usage unknown   -> `settled_unknown`: the full reservation STANDS (never released as if it were zero);
 *  - failure that may have been processed (timeout, unavailable after sending, invalid response)
 *                             -> `failed_unknown`: the full reservation STANDS;
 *  - failure that provably was not processed (rate-limited, authentication, bad request, connection never made)
 *                             -> `released`: tokens and cost are released, but the attempt still counts as a request;
 *  - never closed (the process died after the provider may have been reached) -> stays `open`, fully charged;
 *  - a retry is a new provider request with its OWN reservation (and ledger row). The previous attempt is closed in the same
 *    transaction that takes the new one, so a refused retry changes nothing.
 */
class BudgetGuard
{
    public const OPEN = 'open';

    public const SETTLED = 'settled';

    public const SETTLED_UNKNOWN = 'settled_unknown';

    public const FAILED_UNKNOWN = 'failed_unknown';

    public const RELEASED = 'released';

    private const LIMIT_KEYS = ['daily_requests', 'daily_tokens', 'daily_cost_micro_usd'];

    /** The metrics that are part of the counters-equal-ledger invariant. */
    private const CHARGED = ['requests', 'tokens', 'cost_micro_usd'];

    private const DEADLOCK_RETRIES = 3;

    public function __construct(private readonly UsageMeter $meter, private readonly IntegrityGuard $integrity) {}

    /**
     * @param  ?int  $costMicroUsd  maximum cost of this attempt, null when no complete price is configured
     * @param  ?BudgetReservation  $retryOf  the attempt that just failed and is being retried: closed in the same transaction
     * @param  bool  $retryOfMayHaveBeenProcessed  whether that attempt may have been processed (its reservation then stands)
     * @param  ?string  $reservationId  supply an existing id to make the call idempotent (a repeat charges nothing more)
     *
     * @throws AiBudgetExceededException
     * @throws AiConfigurationException
     */
    public function reserve(
        AiSurface $surface,
        int $estimatedTokens,
        bool $live,
        ?int $costMicroUsd = null,
        ?BudgetReservation $retryOf = null,
        bool $retryOfMayHaveBeenProcessed = true,
        ?string $reservationId = null,
    ): BudgetReservation {
        $scopes = $this->scopeLimits($surface);
        $day = $this->meter->today();

        if ($live) {
            $this->assertLiveLimits($scopes, $costMicroUsd);
        }

        // M5.7.2: a live provider NEVER runs without the independent anchor; any other provider uses it when one is configured.
        $anchored = $this->integrity->requiredFor($live);
        if ($anchored && ! $this->integrity->configured()) {
            throw new AiIntegrityException('No independent integrity anchor is configured; live AI execution is stopped.');
        }

        if ($retryOf !== null && $retryOf->day !== $day) {
            // The retry crosses midnight: the old attempt belongs to yesterday's counters, so it is closed on its own first.
            $this->fail($retryOf, $retryOfMayHaveBeenProcessed);
            $retryOf = null;
        }

        $id = $reservationId ?? strtolower((string) Str::ulid());
        $amounts = ['requests' => 1, 'tokens' => $estimatedTokens];
        if ($costMicroUsd !== null) {
            $amounts['cost_micro_usd'] = $costMicroUsd;
        }

        try {
            $this->ensureCounterRows($day, array_keys($scopes));

            DB::transaction(
                fn () => $this->reserveLocked($id, $day, $surface, $scopes, $amounts, $estimatedTokens, $costMicroUsd, $live, $retryOf, $retryOfMayHaveBeenProcessed, $anchored),
                self::DEADLOCK_RETRIES,
            );
        } catch (AiIntegrityException $e) {
            // Evidence of damage latches the emergency stop (outside the rolled-back transaction); an unreachable anchor only refuses.
            if ($e->mismatch) {
                $this->integrity->latch($e->getMessage());
            }

            throw $e;
        } catch (AiBudgetExceededException|AiConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logLedgerFailure('reserve', $e);

            throw new AiConfigurationException('The AI budget ledger is unavailable; AI execution is stopped.');
        }

        return new BudgetReservation($id, $day, $surface, $estimatedTokens, $costMicroUsd, $retryOf?->id);
    }

    /**
     * Whether every limit a LIVE provider needs is configured, finite and positive for this surface (and for every
     * scope). Used by `ai:preflight`.
     *
     * @return list<string> human-readable problems, empty when fine (never contains a value)
     */
    public function liveLimitProblems(AiSurface $surface): array
    {
        $problems = [];

        try {
            foreach ($this->scopeLimits($surface) as $scope => $limits) {
                foreach (self::LIMIT_KEYS as $k) {
                    if (! is_int($limits[$k]) || $limits[$k] < 1) {
                        $problems[] = "[{$scope}] {$k} must be an explicit positive integer";
                    }
                }
            }
        } catch (AiConfigurationException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    /**
     * Success. The reservation is replaced by the reported usage when known (a lower figure releases only the unused part; a
     * higher one is recorded in full and flags the day). Unknown usage leaves the reservation in place. At most once.
     *
     * @throws AiConfigurationException when the ledger cannot be updated (the reservation then simply stays charged)
     */
    public function settle(BudgetReservation $r, ?int $actualTokens, ?int $costMicroUsd): void
    {
        $violation = null;

        $this->close($r, function (object $row) use ($actualTokens, $costMicroUsd, &$violation): array {
            $reservedCost = $row->reserved_cost_micro_usd === null ? null : (int) $row->reserved_cost_micro_usd;
            $overTokens = $actualTokens !== null && $actualTokens > (int) $row->reserved_tokens;
            $overCost = $costMicroUsd !== null && $reservedCost !== null && $costMicroUsd > $reservedCost;

            // Cost was reserved: the reservation is replaced by the actual figure. Nothing was reserved for cost (offline/dev
            // run): just record what it cost. Otherwise what is held stands.
            $cost = $costMicroUsd !== null && ($reservedCost !== null || $costMicroUsd > 0) ? $costMicroUsd : (int) $row->cost_micro_usd;

            if ($overTokens || $overCost) {
                $violation = ['reserved_tokens' => (int) $row->reserved_tokens, 'reserved_cost' => $reservedCost, 'surface' => $row->surface];
            }

            return [
                'state' => $actualTokens === null ? self::SETTLED_UNKNOWN : self::SETTLED,
                'tokens' => $actualTokens ?? (int) $row->tokens,
                'cost' => $cost,
                'success' => true,
                'failure' => false,
                'violation' => $overTokens || $overCost,
            ];
        });

        if ($violation !== null) {
            $this->logBoundViolation($r->day, $violation, $actualTokens, $costMicroUsd);
        }
    }

    /**
     * Failure of the provider attempt. The attempt always counts as a request (a failing provider cannot be hammered past
     * the request cap). Tokens and cost are released ONLY when the attempt provably was not processed; otherwise the
     * reservation (its tokens AND its maximum cost) stays charged in full. At most once.
     */
    public function fail(BudgetReservation $r, bool $mayHaveBeenProcessed): void
    {
        $this->close($r, fn (object $row): array => $mayHaveBeenProcessed
            ? ['state' => self::FAILED_UNKNOWN, 'tokens' => (int) $row->tokens, 'cost' => (int) $row->cost_micro_usd, 'success' => false, 'failure' => true, 'violation' => false]
            : ['state' => self::RELEASED, 'tokens' => 0, 'cost' => 0, 'success' => false, 'failure' => true, 'violation' => false]);
    }

    /**
     * The last check before bytes leave the server: the reservation named by a request must exist in the ledger, be OPEN and
     * belong to the surface that is asking. An external provider adapter calls this inside send(), so no network request
     * can be made without a valid durable reservation, whatever the caller did.
     *
     * @throws AiConfigurationException on any doubt, including an unreadable ledger
     */
    public function assertOpenReservation(?string $reservationId, AiSurface $surface): void
    {
        if ($reservationId === null || $reservationId === '') {
            throw new AiConfigurationException('No budget reservation accompanies this provider request.');
        }

        try {
            $row = DB::table(UsageMeter::LEDGER_TABLE)->where('id', $reservationId)->first();
        } catch (\Throwable $e) {
            $this->logLedgerFailure('verify', $e);

            throw new AiConfigurationException('The AI budget ledger is unavailable; AI execution is stopped.');
        }

        // M5.7.2: the independent anchor must hold the same reservation. No anchor, no request. (Asked first when the row is
        // missing: an anchor that knows the reservation proves the database lost it.)
        try {
            if ($row === null || $row->state !== self::OPEN || $row->surface !== $surface->value) {
                if ($row === null) {
                    $this->integrity->assertSendAllowed($reservationId, null);
                }

                throw new AiConfigurationException('The budget reservation for this provider request is not valid.');
            }

            $this->integrity->assertSendAllowed($reservationId, $row);
        } catch (AiIntegrityException $e) {
            if ($e->mismatch) {
                $this->integrity->latch($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Counters versus ledger for every scope of a day, as human-readable problems (numbers only, empty when consistent).
     * Read-only; used by `ai:preflight`. A missing counter row counts as zero, so a deleted row is a problem as soon as the
     * ledger shows usage.
     *
     * @return list<string>
     *
     * @throws \Throwable when the database cannot be read
     */
    public function integrityProblems(?string $day = null): array
    {
        $day ??= $this->meter->today();
        $rows = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->get()->keyBy('scope');

        return $this->inconsistencies($day, $rows, UsageMeter::scopes());
    }

    /**
     * Reservations that are still open after `$olderThanSeconds` (a process died between reserving and settling): they stay
     * charged in full, which is the safe direction, but an operator should know.
     */
    public function staleOpenReservations(int $olderThanSeconds = 900): int
    {
        return DB::table(UsageMeter::LEDGER_TABLE)
            ->where('state', self::OPEN)
            ->where('created_at', '<', now()->subSeconds($olderThanSeconds))
            ->count();
    }

    // ------------------------------------------------------------------------------------------------ internals

    /**
     * @param  array<string,array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int}>  $scopes
     * @param  array<string,int>  $amounts  metric => amount
     */
    private function reserveLocked(
        string $id,
        string $day,
        AiSurface $surface,
        array $scopes,
        array $amounts,
        int $estimatedTokens,
        ?int $costMicroUsd,
        bool $live,
        ?BudgetReservation $retryOf,
        bool $retryOfMayHaveBeenProcessed,
        bool $anchored = false,
    ): void {
        $names = array_keys($scopes);

        // The one lock every anchored writer takes first, so the anchor's sequence can be compared exactly.
        if ($anchored) {
            $this->integrity->lockGate();
        }

        $rows = $this->lockCounters($day, $names);

        // M5.7.2: the database history must agree with the independent anchor (it may not be behind, ahead or lower). Checked first, so
        // the more informative verdict wins when both stores are damaged.
        if ($anchored) {
            $this->integrity->verifyForWrite($day, $id);
        }

        $problems = $this->inconsistencies($day, $rows, $names);
        if ($problems !== []) {
            $this->logInconsistency($day, $problems);

            // With an anchor, a counters-versus-ledger mismatch is evidence of damage too: it latches like any other.
            throw $anchored
                ? new AiIntegrityException('The AI usage accounting is inconsistent with its ledger; AI execution is stopped until it is reconciled.', mismatch: true)
                : new AiConfigurationException('The AI usage accounting is inconsistent with its ledger; AI execution is stopped until it is reviewed.');
        }

        if ($live && $this->meter->integrityFlagged($day)) {
            throw new AiConfigurationException('The AI budget integrity flag is set for today; live AI execution is stopped until it is reviewed.');
        }

        $existing = DB::table(UsageMeter::LEDGER_TABLE)->where('id', $id)->first();
        if ($existing !== null) {
            // Idempotent: the same reservation asked for twice is charged once.
            if ($existing->day !== $day || $existing->surface !== $surface->value) {
                throw new AiConfigurationException('The budget reservation id conflicts with an existing reservation.');
            }

            return;
        }

        $closePrevious = null;

        if ($retryOf !== null) {
            $previous = DB::table(UsageMeter::LEDGER_TABLE)->where('id', $retryOf->id)->lockForUpdate()->first();
            if ($previous === null || $previous->state !== self::OPEN) {
                throw new AiConfigurationException('The attempt being retried is not open in the ledger.');
            }

            $closePrevious = [
                'id' => $previous->id,
                'state' => $retryOfMayHaveBeenProcessed ? self::FAILED_UNKNOWN : self::RELEASED,
                'tokens' => $retryOfMayHaveBeenProcessed ? (int) $previous->tokens : 0,
                'cost' => $retryOfMayHaveBeenProcessed ? (int) $previous->cost_micro_usd : 0,
            ];

            $this->transition(
                $day,
                $names,
                $previous,
                $retryOfMayHaveBeenProcessed ? self::FAILED_UNKNOWN : self::RELEASED,
                $retryOfMayHaveBeenProcessed ? (int) $previous->tokens : 0,
                $retryOfMayHaveBeenProcessed ? (int) $previous->cost_micro_usd : 0,
                false,
                false,
                $rows,
            );
        }

        // Refuse in a stable order (scope, then metric) so the message names the binding limit.
        foreach ($scopes as $scope => $limits) {
            foreach ($amounts as $metric => $amount) {
                $limit = $limits['daily_'.$metric];

                if ($limit !== null && (int) $rows[$scope]->{$metric} + $amount > $limit) {
                    throw new AiBudgetExceededException("Daily {$metric} budget exhausted for scope [{$scope}].");
                }
            }
        }

        // The atomic guard itself: the UPDATE only applies when the limit still holds at the instant it runs.
        foreach ($scopes as $scope => $limits) {
            $update = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->where('scope', $scope);
            $set = ['updated_at' => now()];

            foreach ($amounts as $metric => $amount) {
                $limit = $limits['daily_'.$metric];

                if ($limit !== null) {
                    $update->whereRaw("{$metric} + ? <= ?", [$amount, $limit]);
                }

                $set[$metric] = DB::raw("{$metric} + ".(int) $amount);
            }

            if ($update->update($set) !== 1) {
                throw new AiBudgetExceededException("Daily budget exhausted for scope [{$scope}].");
            }
        }

        // Anchor first, ledger second (M5.7.2). Everything that can refuse has already run, so the anchor only ever hears about
        // reservations that are going to be charged. If the anchor accepts and the database then fails, the reservation stays
        // charged in the anchor (conservative) and the next request is refused until an operator reconciles. If the anchor
        // fails, this throws and the transaction - and with it the counters - rolls back: the request is never sent.
        $anchorSeq = $anchored
            ? $this->integrity->recordReservation($id, [
                'day' => $day, 'surface' => $surface->value, 'requests' => 1, 'tokens' => $estimatedTokens, 'cost' => $costMicroUsd ?? 0,
                'reserved_tokens' => $estimatedTokens, 'reserved_cost' => $costMicroUsd, 'retry_of' => $retryOf?->id,
            ], $closePrevious)
            : null;

        DB::table(UsageMeter::LEDGER_TABLE)->insert([
            'id' => $id,
            'day' => $day,
            'surface' => $surface->value,
            'state' => self::OPEN,
            'retry_of' => $retryOf?->id,
            'requests' => 1,
            'tokens' => $estimatedTokens,
            'cost_micro_usd' => $costMicroUsd ?? 0,
            'reserved_tokens' => $estimatedTokens,
            'reserved_cost_micro_usd' => $costMicroUsd,
            'created_at' => now(),
            'closed_at' => null,
        ] + ($anchored ? ['anchor_seq' => $anchorSeq] : []));
    }

    /**
     * Close one reservation: lock the counters (global, surface) and the ledger row, and - only if it is still open - move
     * it to its final state and apply the difference to the counters, all in one transaction.
     *
     * @param  \Closure(object):array{state:string,tokens:int,cost:int,success:bool,failure:bool,violation:bool}  $decide
     */
    private function close(BudgetReservation $r, \Closure $decide): void
    {
        $names = ['global', UsageMeter::surfaceScope($r->surface)];

        try {
            DB::transaction(function () use ($r, $names, $decide): void {
                $anchored = $this->integrity->configured();

                if ($anchored) {
                    $this->integrity->lockGate();
                }

                try {
                    $rows = $this->lockCounters($r->day, $names);
                } catch (AiConfigurationException $e) {
                    // A counter row that held a reservation a moment ago and is gone is a rollback, not a quiet day.
                    throw $anchored ? new AiIntegrityException($e->getMessage(), mismatch: true) : $e;
                }

                $row = DB::table(UsageMeter::LEDGER_TABLE)->where('id', $r->id)->lockForUpdate()->first();
                if ($row === null) {
                    // With an anchor a vanished ledger row is evidence of a rollback (the reservation was real): it latches.
                    throw $anchored
                        ? new AiIntegrityException('The budget reservation is missing from the ledger; AI execution is stopped until it is reconciled.', mismatch: true)
                        : new AiConfigurationException('The budget reservation is missing from the ledger.');
                }

                if ($row->state !== self::OPEN) {
                    return; // already settled/failed (here or in another process): nothing is applied twice
                }

                if (! $anchored && ($row->anchor_seq ?? null) !== null) {
                    throw new AiIntegrityException('This reservation is recorded in an integrity anchor that is not configured; it stays charged.');
                }

                if ($anchored) {
                    $this->integrity->verifyForWrite($r->day);
                }

                $d = $decide($row);

                // The anchor hears about the outcome BEFORE the database does: if the database then fails, the anchor holds the
                // LOWER charge and the database the higher one - the conservative direction, and still consistent.
                if ($anchored) {
                    $this->integrity->recordClose($r->id, $d['state'], $d['tokens'], $d['cost']);
                }

                $this->transition($r->day, $names, $row, $d['state'], $d['tokens'], $d['cost'], $d['success'], $d['failure'], $rows);

                if ($d['violation']) {
                    $this->meter->writeViolationFlag($r->day);
                }
            }, self::DEADLOCK_RETRIES);
        } catch (AiIntegrityException $e) {
            if ($e->mismatch) {
                $this->integrity->latch($e->getMessage());
            }

            throw $e;
        } catch (AiConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logLedgerFailure('close', $e);

            throw new AiConfigurationException('The AI budget ledger is unavailable.');
        }
    }

    /**
     * Move a ledger row to `$state` charging `$tokens`/`$cost`, and apply the DIFFERENCE to every scope's counters. A counter
     * can only ever hold what the ledger rows add up to, so the guard `x + delta >= 0` can never fail on a consistent ledger;
     * if it does, the transaction aborts and the reservation stays charged.
     *
     * @param  list<string>  $names
     * @param  Collection<string,object>  $rows  locked counter rows, kept current
     */
    private function transition(string $day, array $names, object $row, string $state, int $tokens, int $cost, bool $success, bool $failure, Collection $rows): void
    {
        $dTokens = $tokens - (int) $row->tokens;
        $dCost = $cost - (int) $row->cost_micro_usd;

        foreach ($names as $scope) {
            $applied = DB::table(UsageMeter::COUNTERS_TABLE)
                ->where('day', $day)->where('scope', $scope)
                ->whereRaw('tokens + ? >= 0', [$dTokens])
                ->whereRaw('cost_micro_usd + ? >= 0', [$dCost])
                ->update([
                    'tokens' => DB::raw('tokens + '.$dTokens),
                    'cost_micro_usd' => DB::raw('cost_micro_usd + '.$dCost),
                    'successes' => DB::raw('successes + '.($success ? 1 : 0)),
                    'failures' => DB::raw('failures + '.($failure ? 1 : 0)),
                    'updated_at' => now(),
                ]);

            // `$applied` is 0 when the guard refused. A transition that changes nothing at all (a retry-close that releases
            // nothing) may also report 0 on MySQL, which counts changed rows: that case is not an error.
            if ($applied !== 1 && ($dTokens !== 0 || $dCost !== 0 || $success || $failure)) {
                throw new AiConfigurationException('The AI usage counters are inconsistent with the ledger; the reservation stays charged.');
            }

            if (isset($rows[$scope])) {
                $rows[$scope]->tokens = (int) $rows[$scope]->tokens + $dTokens;
                $rows[$scope]->cost_micro_usd = (int) $rows[$scope]->cost_micro_usd + $dCost;
            }
        }

        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $row->id)->update([
            'state' => $state,
            'tokens' => $tokens,
            'cost_micro_usd' => $cost,
            'closed_at' => now(),
        ]);
    }

    /** Counter rows must exist before a transaction can lock them. Idempotent, and never touches an existing row. */
    private function ensureCounterRows(string $day, array $names): void
    {
        DB::table(UsageMeter::COUNTERS_TABLE)->insertOrIgnore(array_map(
            fn (string $scope) => ['day' => $day, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()],
            $names,
        ));
    }

    /**
     * Take the row locks of the given scopes (global sorts before surface.*, which is the one lock order used everywhere)
     * and return the rows. The write comes first so SQLite also takes its write lock before reading; on MySQL/MariaDB the
     * following SELECT ... FOR UPDATE is what reads the locked rows.
     *
     * @param  list<string>  $names
     * @return Collection<string,object>
     *
     * @throws AiConfigurationException when a row that must exist does not (a missing record is never "zero")
     */
    private function lockCounters(string $day, array $names): Collection
    {
        DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->whereIn('scope', $names)->update(['updated_at' => now()]);

        $rows = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->whereIn('scope', $names)->orderBy('scope')->lockForUpdate()->get()->keyBy('scope');

        foreach ($names as $scope) {
            if (! isset($rows[$scope])) {
                throw new AiConfigurationException('An AI usage counter is missing; AI execution is stopped until it is reviewed.');
            }
        }

        return $rows;
    }

    /**
     * The counters-equal-ledger invariant. Any difference - lower (usage was erased) or higher (the ledger lost rows) - means
     * the accounting cannot be trusted.
     *
     * @param  Collection<string,object>  $rows
     * @param  list<string>  $names
     * @return list<string>
     */
    private function inconsistencies(string $day, Collection $rows, array $names): array
    {
        $problems = [];

        foreach ($names as $scope) {
            $ledger = DB::table(UsageMeter::LEDGER_TABLE)->where('day', $day);
            if ($scope !== 'global') {
                $ledger->where('surface', substr($scope, strlen('surface.')));
            }

            $sum = $ledger->selectRaw('COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(tokens), 0) AS tokens, COALESCE(SUM(cost_micro_usd), 0) AS cost_micro_usd')->first();

            foreach (self::CHARGED as $metric) {
                $counter = (int) ($rows[$scope]->{$metric} ?? 0);

                if ($counter !== (int) $sum->{$metric}) {
                    $problems[] = "[{$scope}] {$metric}: counter {$counter} does not match ledger ".(int) $sum->{$metric};
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string,array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int}>  $scopes
     */
    private function assertLiveLimits(array $scopes, ?int $costMicroUsd): void
    {
        foreach ($scopes as $scope => $limits) {
            foreach (self::LIMIT_KEYS as $k) {
                // `null` (not configured) is never "unlimited" for something that spends money. A configured 0 is a hard
                // block: it passes this check and is then refused by the atomic limit check as an exhausted budget.
                if (! is_int($limits[$k])) {
                    throw new AiConfigurationException("No explicit {$k} limit is configured for scope [{$scope}]; a live AI provider will not run without one.");
                }
            }
        }

        if ($costMicroUsd === null) {
            throw new AiConfigurationException('No complete input/output price is configured for the model; a live AI provider will not run without one.');
        }
    }

    /** @param  array{reserved_tokens:int,reserved_cost:?int,surface:string}  $v */
    private function logBoundViolation(string $day, array $v, ?int $actualTokens, ?int $actualCost): void
    {
        try {
            // Numbers only: no prompt, response, identity or credential can be in this context.
            Log::channel(config('ai.logging.channel'))->critical('ai.budget.bound_violated', [
                'day' => $day,
                'surface' => $v['surface'],
                'reserved_tokens' => $v['reserved_tokens'],
                'actual_tokens' => $actualTokens,
                'reserved_cost_micro_usd' => $v['reserved_cost'],
                'actual_cost_micro_usd' => $actualCost,
            ]);
        } catch (\Throwable) {
        }
    }

    /** @param  list<string>  $problems */
    private function logInconsistency(string $day, array $problems): void
    {
        try {
            Log::channel(config('ai.logging.channel'))->critical('ai.budget.ledger_inconsistent', ['day' => $day, 'problems' => $problems]);
        } catch (\Throwable) {
        }
    }

    private function logLedgerFailure(string $operation, \Throwable $e): void
    {
        try {
            // The class only: the message of a database exception can contain SQL and bound values.
            Log::channel(config('ai.logging.channel'))->error('ai.budget.ledger_unavailable', ['operation' => $operation, 'exception' => $e::class]);
        } catch (\Throwable) {
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

    /** @return array{daily_requests:?int,daily_tokens:?int,daily_cost_micro_usd:?int} */
    private function limits(string $path): array
    {
        $raw = (array) config($path, []);
        $out = [];

        foreach (self::LIMIT_KEYS as $k) {
            $v = $raw[$k] ?? null;

            if ($v !== null && (! is_int($v) || $v < 0)) {
                throw new AiConfigurationException("Invalid AI budget value for [{$path}.{$k}].");
            }

            $out[$k] = $v;
        }

        return $out;
    }
}
