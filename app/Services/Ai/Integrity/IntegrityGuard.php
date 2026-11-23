<?php

namespace App\Services\Ai\Integrity;

use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Compares the database's budget history with the INDEPENDENT anchor and decides whether the accounting can be trusted
 * (M5.7.2). The database projection (ledger + counters + kill-switch rows) is used for reporting and locking; the anchor is
 * what proves that the projection has not moved backwards.
 *
 * What is compared (all of it numbers, ids and states):
 *  - the anchor's sequence number against the highest `anchor_seq` in the ledger: equal, or the database lost history (behind)
 *    or the anchor was replaced by an older copy (ahead);
 *  - every anchor record of the day against the ledger: it must exist with the same sequence number and the ledger may charge
 *    the same OR MORE (a ledger that charges more is the conservative direction and is tolerated; one that charges less is not);
 *  - the day's counters against the anchor's totals (counters may be higher, never lower);
 *  - the binding: the database remembers which anchor it belongs to, so an old backup taken before the binding, or another
 *    anchor, is recognised.
 * Any difference is a MISMATCH: the guard latches (reconciliation `required` in the anchor, kill switch engaged in both
 * stores) and nothing runs until an operator reconciles explicitly. An anchor that merely cannot be reached refuses the request
 * without latching.
 *
 * Nothing here ever invents a figure: a missing figure is a problem, never zero.
 */
class IntegrityGuard
{
    /** `ai_runtime_flags` row naming the anchor this database belongs to. It is also the single lock every anchored write takes first. */
    public const BINDING_FLAG = 'integrity_anchor_id';

    public const GENERATION_FLAG = 'kill_switch_generation';

    private const METRICS = ['requests' => 'requests', 'tokens' => 'tokens', 'cost_micro_usd' => 'cost'];

    public function driver(): string
    {
        $driver = config('ai.integrity.anchor.driver');

        return is_string($driver) && $driver !== '' ? $driver : 'none';
    }

    /** True when an anchor is configured (or has been bound explicitly, which tests use for fault injection). */
    public function configured(): bool
    {
        return app()->bound(IntegrityAnchor::class) || $this->driver() === 'file';
    }

    public function requiredFor(bool $live): bool
    {
        return $live || $this->configured();
    }

    public function anchor(): IntegrityAnchor
    {
        if (app()->bound(IntegrityAnchor::class)) {
            return app(IntegrityAnchor::class);
        }

        return match ($this->driver()) {
            'file' => new FileIntegrityAnchor((string) config('ai.integrity.anchor.path', ''), (string) config('ai.integrity.anchor.key', '')),
            default => new UnconfiguredIntegrityAnchor,
        };
    }

    // ------------------------------------------------------------------------------------------ readiness

    /**
     * Everything that stops the anchor being relied on right now, as names only. Used by the live provider gate and by
     * `ai:preflight`. Empty = the anchor is configured, attested, readable, authentic, bound to this database and not under
     * a reconciliation requirement. (It does NOT compare the histories; see {@see problems()}.)
     *
     * @return list<string>
     */
    public function readinessProblems(): array
    {
        if (! $this->configured()) {
            return ['the independent integrity anchor is not configured (AI_INTEGRITY_ANCHOR_DRIVER)'];
        }

        $problems = [];
        $anchor = $this->anchor();

        foreach ($anchor->configurationProblems() as $p) {
            $problems[] = $p;
        }

        if (config('ai.integrity.anchor.independence_attested') !== true) {
            $problems[] = 'the anchor\'s independence from the database has not been attested (AI_INTEGRITY_ANCHOR_INDEPENDENCE_ATTESTED)';
        }

        $ref = config('ai.integrity.anchor.independence_reference');
        if (! is_string($ref) || strlen(trim($ref)) < 3) {
            $problems[] = 'no reference documents where the anchor lives and why it is independent (AI_INTEGRITY_ANCHOR_INDEPENDENCE_REF)';
        }

        if ($problems !== []) {
            return $problems;
        }

        try {
            $snap = $anchor->read();
        } catch (AnchorException $e) {
            return ['the integrity anchor cannot be used ('.$e->kind.')'];
        }

        if (! $snap->reconciliationOk()) {
            $problems[] = 'a reconciliation is required before AI can run again (ai:integrity status)';
        }

        try {
            if ($this->boundTo() !== $snap->anchorId) {
                $problems[] = 'this database is not bound to this integrity anchor (ai:integrity status)';
            }
        } catch (\Throwable) {
            $problems[] = 'the database binding to the anchor cannot be read';
        }

        return $problems;
    }

    /** Whether the ledger has the `anchor_seq` column (read-only). */
    public function schemaReady(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn(UsageMeter::LEDGER_TABLE, 'anchor_seq');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @throws \Throwable when the database cannot be read */
    public function boundTo(): ?string
    {
        $v = DB::table(UsageMeter::FLAGS_TABLE)->where('name', self::BINDING_FLAG)->value('value');

        return is_string($v) && $v !== '' ? $v : null;
    }

    // ------------------------------------------------------------------------------------- verification

    /**
     * The check every anchored write makes INSIDE its transaction, after taking {@see lockGate()}.
     *
     * @param  ?string  $ownId  a reservation this very call already placed in the anchor (a transaction retried after a deadlock)
     *
     * @throws AiIntegrityException
     */
    public function verifyForWrite(string $day, ?string $ownId = null): AnchorSnapshot
    {
        $snap = $this->readSnapshot();

        if (! $snap->reconciliationOk()) {
            throw new AiIntegrityException('A reconciliation of the AI accounting is required; AI execution is stopped.');
        }

        try {
            $bound = $this->boundTo();
        } catch (\Throwable) {
            throw new AiIntegrityException('The AI integrity binding cannot be read; AI execution is stopped.');
        }

        if ($bound !== $snap->anchorId) {
            throw new AiIntegrityException('This database is not bound to the integrity anchor; AI execution is stopped.', mismatch: true);
        }

        $problems = $this->compare($snap, [$day], $ownId);
        if ($problems !== []) {
            $this->log('mismatch', ['problems' => array_slice($problems, 0, 8)]);

            throw new AiIntegrityException('The AI accounting does not match the independent anchor: '.$problems[0], mismatch: true);
        }

        return $snap;
    }

    /**
     * Full comparison over every day (read-only), as problems. `ai:integrity status`, preflight and the end of a reconciliation use it.
     *
     * @return list<string> empty = the database agrees with the anchor
     */
    public function problems(): array
    {
        try {
            $snap = $this->anchor()->read();
        } catch (AnchorException $e) {
            return ['the integrity anchor cannot be used ('.$e->kind.')'];
        }

        $problems = [];

        if (! $snap->reconciliationOk()) {
            $problems[] = 'the anchor requires a reconciliation: '.($snap->reconciliation['reason'] ?? 'no reason recorded');
        }

        try {
            if ($this->boundTo() !== $snap->anchorId) {
                $problems[] = 'the database is not bound to this anchor';
            }

            return array_merge($problems, $this->compare($snap, null, null));
        } catch (\Throwable) {
            return array_merge($problems, ['the database cannot be read']);
        }
    }

    /**
     * Take the lock every anchored writer takes FIRST (before any counter row), so reservations and settlements are
     * serialised against each other whatever day they belong to, and the anchor's sequence can be compared exactly.
     */
    public function lockGate(): void
    {
        DB::table(UsageMeter::FLAGS_TABLE)->where('name', self::BINDING_FLAG)->update(['updated_at' => now()]);
        DB::table(UsageMeter::FLAGS_TABLE)->where('name', self::BINDING_FLAG)->lockForUpdate()->value('value');
    }

    /** @throws AiIntegrityException */
    public function readSnapshot(): AnchorSnapshot
    {
        try {
            return $this->anchor()->read();
        } catch (AnchorException $e) {
            $lost = $e->kind === AnchorException::MISSING && $this->safeBoundTo() !== null;

            throw new AiIntegrityException(
                'The independent integrity anchor cannot be used ('.$e->kind.'); AI execution is stopped.',
                mismatch: $e->kind === AnchorException::CORRUPT || $lost,
            );
        }
    }

    /**
     * The last check before bytes leave the server: the reservation must be in the anchor too - same sequence number, still
     * open - and the anchor must be healthy, bound and not under a reconciliation requirement.
     *
     * @throws AiIntegrityException
     */
    public function assertSendAllowed(string $reservationId, ?object $ledgerRow): void
    {
        $snap = $this->readSnapshot();

        if ($ledgerRow === null) {
            // The anchor knows a reservation that the database no longer has: the database lost history.
            throw new AiIntegrityException('The reservation is not in the database ledger; the request is not sent.', mismatch: $snap->reservation($reservationId) !== null);
        }

        if (! $snap->reconciliationOk()) {
            throw new AiIntegrityException('A reconciliation of the AI accounting is required; AI execution is stopped.');
        }

        try {
            $bound = $this->boundTo();
        } catch (\Throwable) {
            throw new AiIntegrityException('The AI integrity binding cannot be read; AI execution is stopped.');
        }

        if ($bound !== $snap->anchorId) {
            throw new AiIntegrityException('This database is not bound to the integrity anchor; AI execution is stopped.', mismatch: true);
        }

        $record = $snap->reservation($reservationId);
        $seq = $ledgerRow->anchor_seq ?? null;

        if ($record === null || $seq === null || (int) $record['seq'] !== (int) $seq || $record['state'] !== 'open') {
            throw new AiIntegrityException('The anchor does not confirm this reservation; the request is not sent.', mismatch: $record === null || $seq === null || (int) $record['seq'] !== (int) $seq);
        }
    }

    // --------------------------------------------------------------------------------------- anchor writes

    /**
     * Ask the anchor to accept a reservation. Called inside the database transaction after every database check passed, so a
     * refusal earlier never leaves anything in the anchor. Returns the sequence number the ledger row must carry.
     *
     * @param  array<string,mixed>  $record
     * @param  ?array{id:string,state:string,tokens:int,cost:int}  $closePrevious
     *
     * @throws AiIntegrityException when the anchor did not (provably) accept it
     */
    public function recordReservation(string $id, array $record, ?array $closePrevious): int
    {
        try {
            return $this->anchor()->reserve($id, $record, $closePrevious);
        } catch (AnchorException $e) {
            throw new AiIntegrityException('The independent integrity anchor did not accept the reservation ('.$e->kind.'); the request is not sent.');
        }
    }

    /** @throws AiIntegrityException */
    public function recordClose(string $id, string $state, int $tokens, int $cost): void
    {
        try {
            $this->anchor()->close($id, $state, $tokens, $cost);
        } catch (AnchorException $e) {
            throw new AiIntegrityException('The independent integrity anchor did not accept the settlement ('.$e->kind.'); the reservation stays charged.');
        }
    }

    /**
     * Evidence of damage was found: keep AI off until a person decides. Every step is best effort and independent (a
     * missing anchor must not stop the database half, and the reverse), and none of it can raise.
     */
    public function latch(string $reason): void
    {
        $reason = mb_substr('integrity mismatch: '.$reason, 0, 250);

        try {
            $this->anchor()->setReconciliation('required', $reason);
        } catch (\Throwable) {
        }

        try {
            $this->anchor()->setKillSwitch('engaged', $reason);
        } catch (\Throwable) {
        }

        try {
            app(KillSwitch::class)->engageDatabaseOnly($reason);
        } catch (\Throwable) {
        }

        $this->log('latched', []);
    }

    // ------------------------------------------------------------------------------------------- recovery

    /**
     * Create the anchor from the database as it is NOW and bind the database to it. The database is trusted as the truth for
     * everything up to this moment, so this is an explicit decision: with existing ledger rows it needs `$acceptHistory`, and
     * replacing a database that is already bound (a lost anchor) needs `$replaceBound`. The kill switch ends ENGAGED.
     *
     * @return array{anchor_id:string,records:int,seq:int}
     *
     * @throws AiIntegrityException
     */
    public function initialise(string $reason, bool $acceptHistory, bool $replaceBound): array
    {
        if (! $this->configured()) {
            throw new AiIntegrityException('No integrity anchor is configured.');
        }

        $problems = array_filter($this->anchor()->configurationProblems());
        if ($problems !== []) {
            throw new AiIntegrityException('The anchor is not usable: '.reset($problems).'.');
        }

        try {
            $bound = $this->boundTo();
            $rows = DB::table(UsageMeter::LEDGER_TABLE)->count();
        } catch (\Throwable) {
            throw new AiIntegrityException('The database cannot be read.');
        }

        if ($bound !== null && ! $replaceBound) {
            throw new AiIntegrityException('This database is already bound to an anchor; use --replace-bound-anchor only if that anchor is lost, and only after reading the recovery procedure.');
        }

        if ($rows > 0 && ! $acceptHistory) {
            throw new AiIntegrityException("The ledger already holds {$rows} reservation(s); the anchor would start from the database's own history. Use --accept-database-history to confirm that history is trusted.");
        }

        // 16 hex characters: the ai_runtime_flags.value column is 20 wide (a ULID would be silently accepted by SQLite and rejected by MariaDB).
        $anchorId = bin2hex(random_bytes(8));

        try {
            return DB::transaction(function () use ($anchorId, $reason): array {
                $ledger = DB::table(UsageMeter::LEDGER_TABLE)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

                $problems = $this->databaseConsistencyProblems();
                if ($problems !== []) {
                    throw new AiIntegrityException('The database accounting is not self-consistent ('.$problems[0].'); it cannot seed an anchor.');
                }

                $records = [];
                foreach ($ledger as $row) {
                    $records[] = [
                        'id' => $row->id, 'day' => $row->day, 'surface' => $row->surface, 'state' => $row->state,
                        'requests' => (int) $row->requests, 'tokens' => (int) $row->tokens, 'cost' => (int) $row->cost_micro_usd,
                        'reserved_tokens' => (int) $row->reserved_tokens, 'reserved_cost' => $row->reserved_cost_micro_usd === null ? null : (int) $row->reserved_cost_micro_usd,
                        'retry_of' => $row->retry_of,
                    ];
                }

                DB::table(UsageMeter::LEDGER_TABLE)->update(['anchor_seq' => null]);
                foreach ($records as $i => $r) {
                    DB::table(UsageMeter::LEDGER_TABLE)->where('id', $r['id'])->update(['anchor_seq' => $i + 1]);
                }

                $this->writeFlag(self::BINDING_FLAG, $anchorId, 'bound to the independent integrity anchor');

                // Last, so a failure rolls the whole database side back. The kill switch starts ENGAGED in both stores.
                $snap = $this->anchor()->initialise($anchorId, $records, 'engaged', 'initial state: '.$reason);
                $this->writeFlag(KillSwitch::FLAG, KillSwitch::ENGAGED, 'anchor initialised: '.$reason);
                $this->writeFlag(self::GENERATION_FLAG, (string) $snap->kill['generation'], 'kill-switch generation');

                return ['anchor_id' => $anchorId, 'records' => count($records), 'seq' => $snap->seq];
            });
        } catch (AiIntegrityException $e) {
            throw $e;
        } catch (AnchorException $e) {
            throw new AiIntegrityException('The anchor could not be created ('.$e->kind.'): '.$e->getMessage());
        } catch (\Throwable) {
            throw new AiIntegrityException('The database could not be bound to the anchor; nothing was changed.');
        }
    }

    /**
     * Bring the database back in line with the anchor after a restore, a partial loss or an orphaned reservation. It never
     * lowers a charge and never invents one: figures come only from the anchor's own records, or (with `$adoptDatabaseRows`)
     * from database rows the anchor never saw. It does NOT release the kill switch, whatever the outcome.
     *
     * @return array{restored:int,raised:int,adopted:int,counters_rebuilt:int,remaining_problems:list<string>}
     *
     * @throws AiIntegrityException
     */
    public function reconcile(string $reason, bool $rebindDatabase, bool $adoptDatabaseRows): array
    {
        $snap = $this->readSnapshot();

        try {
            $bound = $this->boundTo();
        } catch (\Throwable) {
            throw new AiIntegrityException('The database cannot be read.');
        }

        if ($bound !== $snap->anchorId && ! $rebindDatabase) {
            throw new AiIntegrityException('This database is not bound to the anchor (an older backup, or another anchor). Use --rebind-database to accept the anchor as the truth for the history.');
        }

        $restored = $raised = $adopted = 0;

        try {
            DB::transaction(function () use ($snap, $rebindDatabase, $adoptDatabaseRows, &$restored, &$raised, &$adopted, $bound): void {
                if ($bound !== $snap->anchorId) {
                    $this->writeFlag(self::BINDING_FLAG, $snap->anchorId, 'bound to the independent integrity anchor');
                }
                $this->lockGate();

                $db = DB::table(UsageMeter::LEDGER_TABLE)->lockForUpdate()->get()->keyBy('id');

                // 1. anchor -> database: every anchor record must exist in the ledger, charging at least what the anchor says.
                foreach ($snap->reservations as $id => $r) {
                    $row = $db[$id] ?? null;

                    if ($row === null) {
                        DB::table(UsageMeter::LEDGER_TABLE)->updateOrInsert(['id' => $id], [
                            'day' => $r['day'], 'surface' => $r['surface'], 'state' => $r['state'], 'retry_of' => $r['retry_of'],
                            'requests' => $r['requests'], 'tokens' => $r['tokens'], 'cost_micro_usd' => $r['cost'],
                            'reserved_tokens' => $r['reserved_tokens'], 'reserved_cost_micro_usd' => $r['reserved_cost'],
                            'anchor_seq' => $r['seq'], 'created_at' => now(), 'closed_at' => $r['state'] === 'open' ? null : now(),
                        ]);
                        $restored++;

                        continue;
                    }

                    $lower = (int) $row->requests < (int) $r['requests'] || (int) $row->tokens < (int) $r['tokens'] || (int) $row->cost_micro_usd < (int) $r['cost'];

                    if ($lower || (int) ($row->anchor_seq ?? 0) !== (int) $r['seq']) {
                        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $id)->update([
                            'requests' => max((int) $row->requests, (int) $r['requests']),
                            'tokens' => max((int) $row->tokens, (int) $r['tokens']),
                            'cost_micro_usd' => max((int) $row->cost_micro_usd, (int) $r['cost']),
                            'state' => $lower && $row->state === 'released' ? $r['state'] : $row->state,
                            'anchor_seq' => $r['seq'],
                        ]);
                        $lower ? $raised++ : null;
                    }
                }

                // 2. database -> anchor (explicit decision): rows the anchor never saw are handed to it, never dropped.
                $unknown = $db->filter(fn ($row, $id) => $snap->reservation((string) $id) === null)->sortBy(fn ($row) => [(string) $row->created_at, (string) $row->id]);

                if ($unknown->isNotEmpty() && ! $adoptDatabaseRows) {
                    throw new AiIntegrityException($unknown->count().' database reservation(s) are unknown to the anchor (the anchor may be older than the database). Use --adopt-database-rows to hand them to the anchor.');
                }

                DB::table(UsageMeter::LEDGER_TABLE)->whereIn('id', $unknown->keys()->all())->update(['anchor_seq' => null]);

                foreach ($unknown as $id => $row) {
                    $seq = $this->anchor()->reserve((string) $id, [
                        'day' => $row->day, 'surface' => $row->surface, 'requests' => (int) $row->requests, 'tokens' => (int) $row->tokens,
                        'cost' => (int) $row->cost_micro_usd, 'reserved_tokens' => (int) $row->reserved_tokens,
                        'reserved_cost' => $row->reserved_cost_micro_usd === null ? null : (int) $row->reserved_cost_micro_usd, 'retry_of' => $row->retry_of,
                    ]);

                    if ($row->state !== 'open') {
                        $this->anchor()->close((string) $id, (string) $row->state, (int) $row->tokens, (int) $row->cost_micro_usd);
                    }

                    DB::table(UsageMeter::LEDGER_TABLE)->where('id', $id)->update(['anchor_seq' => $seq]);
                    $adopted++;
                }
            }, 3);
        } catch (AiIntegrityException $e) {
            throw $e;
        } catch (AnchorException $e) {
            throw new AiIntegrityException('The anchor refused a change ('.$e->kind.'); the reconciliation stopped.');
        } catch (\Throwable) {
            throw new AiIntegrityException('The reconciliation could not be completed; the database was left as it was.');
        }

        $rebuilt = $this->rebuildCounters();

        $remaining = $this->problems();
        // `problems()` also reports a pending `required` status; that is exactly what this call resolves, so judge the rest.
        $remaining = array_values(array_filter($remaining, fn ($p) => ! str_starts_with($p, 'the anchor requires a reconciliation')));

        if ($remaining === []) {
            try {
                $this->anchor()->setReconciliation('ok', mb_substr('reconciled: '.$reason, 0, 250));
            } catch (AnchorException $e) {
                throw new AiIntegrityException('The accounting agrees, but the anchor could not record the reconciliation ('.$e->kind.').');
            }
        }

        return ['restored' => $restored, 'raised' => $raised, 'adopted' => $adopted, 'counters_rebuilt' => $rebuilt, 'remaining_problems' => $remaining];
    }

    /**
     * An operator-readable summary (never a secret).
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $out = ['configured' => $this->configured(), 'driver' => app()->bound(IntegrityAnchor::class) ? 'custom' : $this->driver(), 'readiness' => $this->readinessProblems()];

        try {
            $snap = $this->anchor()->read();
            $out += [
                'anchor_readable' => true, 'anchor_id' => $snap->anchorId, 'anchor_seq' => $snap->seq, 'anchor_records' => count($snap->reservations),
                'reconciliation' => $snap->reconciliation['status'], 'reconciliation_reason' => $snap->reconciliation['reason'],
                'kill_state' => $snap->kill['state'], 'kill_generation' => $snap->kill['generation'],
            ];
        } catch (AnchorException $e) {
            $out += ['anchor_readable' => false, 'anchor_error' => $e->kind];
        }

        try {
            $out['database_bound_to'] = $this->boundTo();
            $out['database_seq'] = (int) DB::table(UsageMeter::LEDGER_TABLE)->max('anchor_seq');
            $out['database_records'] = DB::table(UsageMeter::LEDGER_TABLE)->count();
        } catch (\Throwable) {
            $out['database_readable'] = false;
        }

        $out['problems'] = $this->configured() ? $this->problems() : [];

        return $out;
    }

    // ------------------------------------------------------------------------------------------ internals

    /**
     * @param  ?list<string>  $days  null = every day either side knows
     * @return list<string>
     */
    private function compare(AnchorSnapshot $snap, ?array $days, ?string $ownId): array
    {
        $problems = [];

        $own = $ownId !== null ? $snap->reservation($ownId) : null;
        $ownInDb = $own !== null && DB::table(UsageMeter::LEDGER_TABLE)->where('id', $ownId)->exists();
        $skipOwn = $own !== null && ! $ownInDb;

        $expectedSeq = $snap->seq;
        if ($skipOwn) {
            if ((int) $own['seq'] !== $snap->seq) {
                $problems[] = 'another reservation reached the anchor after this one';
            }
            $expectedSeq = $snap->seq - 1;
        }

        $dbSeq = (int) DB::table(UsageMeter::LEDGER_TABLE)->max('anchor_seq');

        if ($dbSeq < $expectedSeq) {
            $problems[] = "the database ledger is behind the anchor (anchor sequence {$expectedSeq}, database {$dbSeq}): history was lost or rolled back";
        } elseif ($dbSeq > $expectedSeq) {
            $problems[] = "the database ledger is ahead of the anchor (anchor sequence {$expectedSeq}, database {$dbSeq}): the anchor was replaced or restored";
        }

        $days ??= array_values(array_unique(array_merge(
            array_map(fn ($r) => $r['day'], array_values($snap->reservations)),
            DB::table(UsageMeter::LEDGER_TABLE)->distinct()->pluck('day')->all(),
            DB::table(UsageMeter::COUNTERS_TABLE)->distinct()->pluck('day')->all(),
        )));

        foreach ($days as $day) {
            $rows = DB::table(UsageMeter::LEDGER_TABLE)->where('day', $day)->get()->keyBy('id');
            $totals = $snap->totals($day);

            $missing = $short = $unanchored = $extra = 0;

            foreach ($snap->recordsOfDay($day) as $r) {
                if ($skipOwn && $r['id'] === $ownId) {
                    foreach (self::METRICS as $metric => $key) {
                        $totals['global'][$metric] -= (int) $r[$key];
                        $totals['surface.'.$r['surface']][$metric] -= (int) $r[$key];
                    }

                    continue;
                }

                $row = $rows[$r['id']] ?? null;

                if ($row === null) {
                    $missing++;
                } elseif ((int) ($row->anchor_seq ?? 0) !== (int) $r['seq']) {
                    $missing++;
                } elseif ((int) $row->requests < (int) $r['requests'] || (int) $row->tokens < (int) $r['tokens'] || (int) $row->cost_micro_usd < (int) $r['cost']) {
                    $short++;
                }
            }

            foreach ($rows as $id => $row) {
                if (($row->anchor_seq ?? null) === null) {
                    $unanchored++;
                } elseif ($snap->reservation((string) $id) === null) {
                    $extra++;
                }
            }

            if ($missing > 0) {
                $problems[] = "{$missing} anchor reservation(s) of {$day} are missing from the database ledger";
            }
            if ($short > 0) {
                $problems[] = "{$short} reservation(s) of {$day} charge less in the database than in the anchor";
            }
            if ($unanchored > 0) {
                $problems[] = "{$unanchored} ledger reservation(s) of {$day} were never recorded in the anchor";
            }
            if ($extra > 0) {
                $problems[] = "{$extra} ledger reservation(s) of {$day} are unknown to the anchor";
            }

            $counters = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->get()->keyBy('scope');

            foreach ($totals as $scope => $t) {
                foreach (self::METRICS as $metric => $key) {
                    $have = (int) ($counters[$scope]->{$metric} ?? 0);

                    if ($have < $t[$metric]) {
                        $problems[] = "[{$scope}] {$day} {$metric}: database counter {$have} is below the anchor's {$t[$metric]}";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Counters equal ledger sums, for every day (the pre-existing invariant, extended over all days for an initial import).
     *
     * @return list<string>
     */
    private function databaseConsistencyProblems(): array
    {
        $problems = [];

        foreach (DB::table(UsageMeter::LEDGER_TABLE)->distinct()->pluck('day') as $day) {
            $counters = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->get()->keyBy('scope');

            foreach (UsageMeter::scopes() as $scope) {
                $q = DB::table(UsageMeter::LEDGER_TABLE)->where('day', $day);
                if ($scope !== 'global') {
                    $q->where('surface', substr($scope, strlen('surface.')));
                }
                $sum = $q->selectRaw('COALESCE(SUM(requests),0) AS requests, COALESCE(SUM(tokens),0) AS tokens, COALESCE(SUM(cost_micro_usd),0) AS cost_micro_usd')->first();

                foreach (array_keys(self::METRICS) as $m) {
                    if ((int) ($counters[$scope]->{$m} ?? 0) !== (int) $sum->{$m}) {
                        $problems[] = "[{$scope}] {$day} {$m}: counter does not match the ledger";
                    }
                }
            }
        }

        return $problems;
    }

    /** Counters are DEFINED as the ledger sums; after a reconciliation they are made so again. Returns the number of rows written. */
    private function rebuildCounters(): int
    {
        $written = 0;

        DB::transaction(function () use (&$written): void {
            foreach (DB::table(UsageMeter::LEDGER_TABLE)->distinct()->pluck('day') as $day) {
                foreach (UsageMeter::scopes() as $scope) {
                    $q = DB::table(UsageMeter::LEDGER_TABLE)->where('day', $day);
                    if ($scope !== 'global') {
                        $q->where('surface', substr($scope, strlen('surface.')));
                    }

                    $sum = $q->selectRaw('COALESCE(SUM(requests),0) AS requests, COALESCE(SUM(tokens),0) AS tokens, COALESCE(SUM(cost_micro_usd),0) AS cost_micro_usd')->first();
                    $outcomes = (clone $q)->whereIn('state', ['settled', 'settled_unknown'])->count();
                    $failures = (clone $q)->whereIn('state', ['failed_unknown', 'released'])->count();

                    $existing = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day)->where('scope', $scope)->first();
                    $values = [
                        'requests' => (int) $sum->requests, 'tokens' => (int) $sum->tokens, 'cost_micro_usd' => (int) $sum->cost_micro_usd,
                        'successes' => max((int) ($existing->successes ?? 0), $outcomes), 'failures' => max((int) ($existing->failures ?? 0), $failures),
                        'updated_at' => now(),
                    ];

                    if ($existing === null) {
                        DB::table(UsageMeter::COUNTERS_TABLE)->insert(['day' => $day, 'scope' => $scope, 'created_at' => now()] + $values);
                    } else {
                        DB::table(UsageMeter::COUNTERS_TABLE)->where('id', $existing->id)->update($values);
                    }
                    $written++;
                }
            }
        });

        return $written;
    }

    private function writeFlag(string $name, string $value, string $reason): void
    {
        DB::table(UsageMeter::FLAGS_TABLE)->upsert(
            [['name' => $name, 'value' => $value, 'reason' => mb_substr($reason, 0, 255), 'created_at' => now(), 'updated_at' => now()]],
            ['name'],
            ['value', 'reason', 'updated_at'],
        );
    }

    private function safeBoundTo(): ?string
    {
        try {
            return $this->boundTo();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $context */
    private function log(string $event, array $context): void
    {
        try {
            Log::channel(config('ai.logging.channel'))->critical('ai.integrity.'.$event, $context);
        } catch (\Throwable) {
        }
    }
}
