<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiSurface;
use Illuminate\Support\Facades\DB;

/**
 * READ side of the durable daily usage counters (table `ai_usage_counters`). COUNTS ONLY: no prompt, response, identity
 * or message text is ever stored. Per-request detail (provider, model, surface, request id, tokens, duration, outcome)
 * goes to the redacted structured log instead.
 *
 * Writing is NOT done here: every change to a counter is made by {@see BudgetGuard}, inside a transaction, together with
 * the matching ledger row (`ai_budget_reservations`). That is what makes the counters reliable: nothing else can move
 * them, and they can always be re-derived from, and checked against, the ledger.
 *
 * The state lives in the database, not in the Laravel cache, so `php artisan cache:clear`, a cache eviction, a restart or a
 * deploy cannot reset it. A database that cannot be read throws: callers fail closed and never treat it as "0 used".
 *
 * Scopes: global | surface.<name>.   Metrics: requests, tokens, cost_micro_usd, successes, failures.
 * The "budget violation" flag of a day (a provider reported more usage than was reserved) is a row in `ai_runtime_flags`.
 */
class UsageMeter
{
    public const METRICS = ['requests', 'tokens', 'cost_micro_usd', 'successes', 'failures'];

    public const COUNTERS_TABLE = 'ai_usage_counters';

    public const LEDGER_TABLE = 'ai_budget_reservations';

    public const FLAGS_TABLE = 'ai_runtime_flags';

    public function today(): string
    {
        return now()->format('Ymd');
    }

    public static function surfaceScope(AiSurface $surface): string
    {
        return 'surface.'.$surface->value;
    }

    /** @return list<string> every scope that can have a counter row */
    public static function scopes(): array
    {
        $scopes = ['global'];
        foreach (AiSurface::cases() as $surface) {
            $scopes[] = self::surfaceScope($surface);
        }

        return $scopes;
    }

    /** True once a provider reported more usage than was reserved today (the bound's assumption failed). */
    public function integrityFlagged(string $day): bool
    {
        return DB::table(self::FLAGS_TABLE)->where('name', self::violationFlag($day))->exists();
    }

    public function flagIntegrityViolation(string $day): void
    {
        $this->writeViolationFlag($day);
    }

    public static function violationFlag(string $day): string
    {
        return 'budget_violation:'.$day;
    }

    /** @internal also called by BudgetGuard inside its settlement transaction */
    public function writeViolationFlag(string $day): void
    {
        DB::table(self::FLAGS_TABLE)->upsert(
            [['name' => self::violationFlag($day), 'value' => 'set', 'reason' => 'a provider reported more usage than was reserved', 'created_at' => now(), 'updated_at' => now()]],
            ['name'],
            ['value', 'reason', 'updated_at'],
        );
    }

    /**
     * One counter. A day/scope with no row yet reads 0 - which is TRUE for a day nothing has been reserved on; whether a
     * missing or altered row contradicts the ledger is judged by BudgetGuard (reservations) and `ai:preflight`.
     *
     * @throws \Throwable when the database cannot be read
     */
    public function get(string $day, string $scope, string $metric): int
    {
        if (! in_array($metric, self::METRICS, true)) {
            return 0;
        }

        return (int) (DB::table(self::COUNTERS_TABLE)->where('day', $day)->where('scope', $scope)->value($metric) ?? 0);
    }

    /**
     * Everything counted for a day, for reports and tests.
     *
     * @return array<string,array<string,int>>
     */
    public function snapshot(?string $day = null): array
    {
        $day ??= $this->today();

        $rows = DB::table(self::COUNTERS_TABLE)->where('day', $day)->get()->keyBy('scope');

        $out = [];
        foreach (self::scopes() as $scope) {
            foreach (self::METRICS as $metric) {
                $out[$scope][$metric] = (int) ($rows[$scope]->{$metric} ?? 0);
            }
        }

        return $out;
    }
}
