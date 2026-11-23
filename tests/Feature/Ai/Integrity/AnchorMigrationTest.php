<?php

namespace Tests\Feature\Ai\Integrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Ai\Support\DurableAiState;
use Tests\TestCase;

/**
 * The M5.7.2 migration (`anchor_seq` on the ledger): additive, idempotent, reversible, existing rows preserved, the table stays InnoDB.
 * Runs on SQLite and on MariaDB (where it also asserts InnoDB and the unique index).
 */
class AnchorMigrationTest extends TestCase
{
    use DurableAiState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropDurableAiTables();
        $this->durableMigration()->up();
        $this->integrityMigration()->down(); // start from the M5.7.1 shape
    }

    protected function tearDown(): void
    {
        $this->durableMigration()->up();
        $this->integrityMigration()->up(); // leave a shared (MariaDB) database as the project's migrations expect

        parent::tearDown();
    }

    private function insertRow(string $id, string $day = '20261101'): void
    {
        DB::table('ai_budget_reservations')->insert([
            'id' => $id, 'day' => $day, 'surface' => 'vendor', 'state' => 'settled', 'requests' => 1, 'tokens' => 33, 'cost_micro_usd' => 7,
            'reserved_tokens' => 40, 'reserved_cost_micro_usd' => 9, 'created_at' => now(), 'closed_at' => now(),
        ]);
    }

    public function test_it_adds_a_nullable_unique_column_and_keeps_existing_rows_unchanged(): void
    {
        $this->insertRow('row-before-the-migration');
        DB::table('ai_usage_counters')->insert(['day' => '20261101', 'scope' => 'global', 'requests' => 1, 'tokens' => 33, 'cost_micro_usd' => 7]);
        $this->assertFalse(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));

        $this->integrityMigration()->up();

        $this->assertTrue(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));
        $row = DB::table('ai_budget_reservations')->first();
        $this->assertSame('row-before-the-migration', $row->id);
        $this->assertNull($row->anchor_seq, 'existing rows are un-anchored until ai:integrity initialise numbers them');
        $this->assertSame([33, 7, 'settled'], [(int) $row->tokens, (int) $row->cost_micro_usd, $row->state]);
        $this->assertSame(1, DB::table('ai_usage_counters')->count());

        // several NULLs may coexist, but one number identifies one reservation
        $this->insertRow('second-null');
        DB::table('ai_budget_reservations')->where('id', 'row-before-the-migration')->update(['anchor_seq' => 5]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('ai_budget_reservations')->where('id', 'second-null')->update(['anchor_seq' => 5]);
    }

    public function test_reapplying_is_a_no_op_and_never_touches_data(): void
    {
        $this->integrityMigration()->up();
        $this->insertRow('r1');
        DB::table('ai_budget_reservations')->where('id', 'r1')->update(['anchor_seq' => 9]);

        $this->integrityMigration()->up();
        $this->integrityMigration()->up();

        $this->assertSame(9, (int) DB::table('ai_budget_reservations')->where('id', 'r1')->value('anchor_seq'));
        $this->assertSame(1, DB::table('ai_budget_reservations')->count());
    }

    public function test_rolling_back_removes_only_the_column_and_reapplying_restores_the_shape(): void
    {
        $this->integrityMigration()->up();
        $this->insertRow('keep-me');
        DB::table('ai_budget_reservations')->where('id', 'keep-me')->update(['anchor_seq' => 3]);

        $this->integrityMigration()->down();

        $this->assertFalse(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));
        $this->assertSame(['keep-me'], DB::table('ai_budget_reservations')->pluck('id')->all(), 'rows survive a rollback');
        $this->assertSame(33, (int) DB::table('ai_budget_reservations')->value('tokens'));
        $this->assertTrue(Schema::hasTable('ai_usage_counters') && Schema::hasTable('ai_runtime_flags'), 'the M5.7.1 tables are untouched');
        $this->assertSame('engaged', DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('value'), 'the shipped kill-switch state is untouched');

        $this->integrityMigration()->down(); // rolling back twice is harmless
        $this->integrityMigration()->up();
        $this->assertTrue(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));
        $this->assertNull(DB::table('ai_budget_reservations')->where('id', 'keep-me')->value('anchor_seq'));
    }

    public function test_the_framework_runs_and_rolls_back_exactly_this_migration(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Runs migrate against the throw-away in-memory SQLite database only; a shared database is covered by up()/down() above.');
        }

        $args = ['--path' => self::INTEGRITY_MIGRATION, '--realpath' => false, '--force' => true];

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate', $args));
        $this->assertTrue(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate', $args), 'a second run has nothing to do');

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate:rollback', $args));
        $this->assertFalse(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate', $args), 're-applies cleanly after a rollback');
        $this->assertTrue(Schema::hasColumn('ai_budget_reservations', 'anchor_seq'));
    }

    public function test_the_ledger_stays_innodb_on_mysql_and_mariadb(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Engine assertions apply to MySQL/MariaDB.');
        }

        $this->integrityMigration()->up();

        $engine = DB::selectOne('SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['ai_budget_reservations'])->e;
        $this->assertSame('INNODB', strtoupper($engine));

        $unique = DB::selectOne('SELECT NON_UNIQUE AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', ['ai_budget_reservations', 'ai_budget_reservations_anchor_seq_unique']);
        $this->assertSame(0, (int) $unique->n);
    }
}
