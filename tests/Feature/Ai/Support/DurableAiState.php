<?php

namespace Tests\Feature\Ai\Support;

use App\Services\Ai\Safety\KillSwitch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The durable AI tables (budget ledger, counters, kill-switch flag) for tests that do not run every migration.
 *
 * The in-memory SQLite test database starts empty, so the real migration's up() creates just these three tables (it is
 * idempotent, so a RefreshDatabase test that already ran it is unaffected). Every test starts from a clean slate with the
 * kill switch RELEASED - the shipped state is ENGAGED, which is what the dedicated kill-switch tests assert separately.
 */
trait DurableAiState
{
    use UsesIntegrityAnchor;

    /** Budget counters, reservation ledger and the kill-switch flag: counts, ids and state only - never message text. */
    public const DURABLE_TABLES = ['ai_usage_counters', 'ai_budget_reservations', 'ai_runtime_flags'];

    public const DURABLE_MIGRATION = 'database/migrations/2026_11_23_000001_create_ai_durable_budget_and_kill_switch_tables.php';

    protected function durableMigration(): object
    {
        return require base_path(self::DURABLE_MIGRATION);
    }

    protected function prepareDurableAiState(bool $releaseKillSwitch = true): void
    {
        $this->durableMigration()->up();
        $this->integrityMigration()->up();

        foreach (['ai_budget_reservations', 'ai_usage_counters', 'ai_runtime_flags'] as $table) {
            DB::table($table)->delete();
        }

        // The migration seeds the flag ENGAGED; a test starts released unless it says otherwise.
        $this->durableMigration()->up();

        // A test that uses the independent anchor gets a fresh, empty anchor bound to the emptied database.
        if ($this->anchorDir !== null) {
            $this->rebuildTestAnchor();
        }

        if ($releaseKillSwitch) {
            app(KillSwitch::class)->release('test setup');
        }
    }

    protected function dropDurableAiTables(): void
    {
        Schema::dropIfExists('ai_runtime_flags');
        Schema::dropIfExists('ai_budget_reservations');
        Schema::dropIfExists('ai_usage_counters');
    }
}
