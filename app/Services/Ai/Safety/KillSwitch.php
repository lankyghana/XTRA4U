<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Integrity\AnchorException;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\DB;

/**
 * The global off switch, in two halves that BOTH must allow a request:
 *   1. config('ai.enabled')  - off by default; changing it needs a config change/redeploy;
 *   2. a DURABLE runtime flag - `php artisan ai:kill-switch engage|release`. It flips instantly, needs no redeploy or config
 *      re-cache, and - because it is not a cache entry - it is NOT reset by `php artisan cache:clear`, a cache eviction, a
 *      restart, a deploy or a queue/worker recycle.
 *
 * Where the durable flag lives (M5.7.2):
 *   - always: the `kill_switch` row of `ai_runtime_flags` (with a `kill_switch_generation` row);
 *   - when an independent integrity anchor is configured: ALSO in the anchor, which is authoritative. A request is allowed only
 *     when the database row says `released`, the anchor says `released`, the anchor is not waiting for a reconciliation, and both
 *     carry the SAME generation. So restoring an older database - one that still says `released` - cannot re-enable AI: the
 *     anchor's newer `engaged` state (or its higher generation) wins. Releasing writes the anchor first and the database second,
 *     engaging the other way round, so a half-finished change always leaves AI OFF.
 *
 * Fails CLOSED, in every way it can fail:
 *   - the migration seeds the row ENGAGED, so a fresh installation is off until an operator releases it on purpose;
 *   - only the exact value `released` lets AI run: `engaged`, any other value, a missing row, a missing table, an unreachable
 *     database, an unreadable, unauthentic or disagreeing anchor or any exception while reading all count as ENGAGED.
 * There is no in-process cache of the state, so an engagement takes effect on the next request in every process.
 *
 * Engaging and releasing only write the flag (and the anchor): no order, wallet, payment, fulfilment or notification code runs.
 * Releasing never bypasses another gate: budgets, the privacy approval and the provider checks are evaluated per request as before.
 */
class KillSwitch
{
    public const FLAG = 'kill_switch';

    public const ENGAGED = 'engaged';

    public const RELEASED = 'released';

    public function engaged(): bool
    {
        try {
            $rows = DB::table(UsageMeter::FLAGS_TABLE)->whereIn('name', [self::FLAG, IntegrityGuard::GENERATION_FLAG])->pluck('value', 'name');

            if (($rows[self::FLAG] ?? null) !== self::RELEASED) {
                return true;
            }

            $guard = app(IntegrityGuard::class);
            if (! $guard->configured()) {
                return false; // M5.7.1 behaviour; a live provider is separately refused without an anchor
            }

            $snap = $guard->anchor()->read();

            return $snap->kill['state'] !== self::RELEASED
                || ! $snap->reconciliationOk()
                || ! isset($rows[IntegrityGuard::GENERATION_FLAG])
                || (int) $rows[IntegrityGuard::GENERATION_FLAG] !== (int) $snap->kill['generation'];
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @throws \Throwable when the state cannot be written everywhere it must be: an engagement that did not fully happen
     *                    must not look like one that did (it is still engaged wherever it WAS written)
     */
    public function engage(?string $reason = null): void
    {
        $reason ??= 'engaged by an operator';
        $guard = app(IntegrityGuard::class);
        $generation = null;
        $anchorFailure = null;

        if ($guard->configured()) {
            try {
                $generation = $guard->anchor()->setKillSwitch(self::ENGAGED, $reason)['generation'];
            } catch (AnchorException $e) {
                $anchorFailure = $e;
            }
        }

        // The database half is written even when the anchor could not be: more "engaged" is always the safe direction.
        $this->writeDatabase(self::ENGAGED, $reason, $generation);

        if ($anchorFailure !== null) {
            throw new AiIntegrityException('The kill switch is engaged in the database, but the independent anchor could not be updated ('.$anchorFailure->kind.').');
        }
    }

    /** The database half only (used when evidence of damage is found and the anchor is itself in doubt). */
    public function engageDatabaseOnly(string $reason): void
    {
        $this->writeDatabase(self::ENGAGED, $reason, null);
    }

    /**
     * Anchored: refused unless the anchor is healthy, bound, free of any reconciliation requirement and in full agreement
     * with the database; the anchor is released FIRST, so a failure in between leaves AI off.
     *
     * @throws \Throwable
     */
    public function release(?string $reason = null): void
    {
        $reason ??= 'released by an operator';
        $guard = app(IntegrityGuard::class);

        if (! $guard->configured()) {
            $this->writeDatabase(self::RELEASED, $reason, null);

            return;
        }

        $problems = $guard->readinessProblems();
        if ($problems !== []) {
            throw new AiIntegrityException('The kill switch cannot be released: '.$problems[0].'.');
        }

        $problems = $guard->problems();
        if ($problems !== []) {
            throw new AiIntegrityException('The kill switch cannot be released: '.$problems[0].'.', mismatch: true);
        }

        try {
            $generation = $guard->anchor()->setKillSwitch(self::RELEASED, $reason)['generation'];
        } catch (AnchorException $e) {
            throw new AiIntegrityException('The kill switch was not released: the independent anchor could not be updated ('.$e->kind.').');
        }

        $this->writeDatabase(self::RELEASED, $reason, $generation);
    }

    /**
     * What an operator sees. `readable` is false when the state could not be read (which counts as engaged).
     *
     * @return array{engaged:bool,readable:bool,state:?string,reason:?string,changed_at:?string,anchor:array<string,mixed>}
     */
    public function status(): array
    {
        $guard = app(IntegrityGuard::class);
        $anchor = ['configured' => $guard->configured(), 'readable' => null, 'state' => null, 'generation' => null, 'database_generation' => null, 'agrees' => null];

        try {
            $row = DB::table(UsageMeter::FLAGS_TABLE)->where('name', self::FLAG)->first();
            $anchor['database_generation'] = ($g = DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::GENERATION_FLAG)->value('value')) === null ? null : (int) $g;
        } catch (\Throwable) {
            return ['engaged' => true, 'readable' => false, 'state' => null, 'reason' => null, 'changed_at' => null, 'anchor' => $anchor];
        }

        if ($anchor['configured']) {
            try {
                $snap = $guard->anchor()->read();
                $anchor['readable'] = true;
                $anchor['state'] = $snap->kill['state'];
                $anchor['generation'] = $snap->kill['generation'];
                $anchor['agrees'] = $snap->kill['state'] === ($row->value ?? null) && $snap->kill['generation'] === $anchor['database_generation'];
            } catch (\Throwable) {
                $anchor['readable'] = false;
            }
        }

        return [
            'engaged' => $this->engaged(),
            'readable' => true,
            'state' => $row->value ?? null,
            'reason' => $row->reason ?? null,
            'changed_at' => isset($row->updated_at) ? (string) $row->updated_at : null,
            'anchor' => $anchor,
        ];
    }

    public function engineEnabled(): bool
    {
        return config('ai.enabled') === true && ! $this->engaged();
    }

    public function surfaceEnabled(AiSurface $surface): bool
    {
        return $this->engineEnabled() && config("ai.surfaces.{$surface->value}.enabled") === true;
    }

    private function writeDatabase(string $value, string $reason, ?int $generation): void
    {
        DB::transaction(function () use ($value, $reason, $generation): void {
            DB::table(UsageMeter::FLAGS_TABLE)->upsert(
                [['name' => self::FLAG, 'value' => $value, 'reason' => mb_substr($reason, 0, 255), 'created_at' => now(), 'updated_at' => now()]],
                ['name'],
                ['value', 'reason', 'updated_at'],
            );

            // Without an anchor the generation is just a counter kept for traceability; with one it mirrors the anchor's.
            $generation ??= ((int) DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::GENERATION_FLAG)->value('value')) + 1;

            DB::table(UsageMeter::FLAGS_TABLE)->upsert(
                [['name' => IntegrityGuard::GENERATION_FLAG, 'value' => (string) $generation, 'reason' => 'kill-switch generation', 'created_at' => now(), 'updated_at' => now()]],
                ['name'],
                ['value', 'reason', 'updated_at'],
            );
        });
    }
}
