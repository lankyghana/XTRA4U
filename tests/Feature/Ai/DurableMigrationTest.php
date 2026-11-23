<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Ai\Support\DurableAiState;
use Tests\TestCase;

/**
 * The M5.7.1 migration: creation, constraints, the fail-closed seed, re-application that never overwrites state,
 * rollback, and no effect on any other table. Runs on SQLite and on MariaDB (where it also asserts InnoDB).
 */
class DurableMigrationTest extends TestCase
{
    use DurableAiState;

    private const SENTINEL = 'zz_migration_sentinel';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropDurableAiTables();
        Schema::dropIfExists(self::SENTINEL);
        Schema::create(self::SENTINEL, function ($table) {
            $table->id();
            $table->string('note');
        });
        DB::table(self::SENTINEL)->insert(['note' => 'must survive every migration step']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::SENTINEL);
        $this->durableMigration()->up(); // leave a shared (MariaDB) database as the project's migrations expect
        $this->integrityMigration()->up();

        parent::tearDown();
    }

    private function assertSentinelIntact(): void
    {
        $this->assertSame(['must survive every migration step'], DB::table(self::SENTINEL)->pluck('note')->all());
    }

    public function test_a_fresh_migration_creates_the_three_tables_seeded_engaged(): void
    {
        foreach (self::DURABLE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->durableMigration()->up();

        foreach (self::DURABLE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $flag = DB::table('ai_runtime_flags')->where('name', 'kill_switch')->first();
        $this->assertSame('engaged', $flag->value, 'AI is off until an operator releases it');
        $this->assertSame(1, DB::table('ai_runtime_flags')->count());
        $this->assertSame(0, DB::table('ai_usage_counters')->count());
        $this->assertSame(0, DB::table('ai_budget_reservations')->count());
        $this->assertSentinelIntact();
    }

    public function test_the_unique_constraints_hold(): void
    {
        $this->durableMigration()->up();
        $row = ['day' => '20260101', 'scope' => 'global', 'created_at' => now(), 'updated_at' => now()];

        DB::table('ai_usage_counters')->insert($row);
        $this->assertThrows(fn () => DB::table('ai_usage_counters')->insert($row), QueryException::class);
        DB::table('ai_usage_counters')->insert(['scope' => 'surface.vendor'] + $row); // another scope is fine
        DB::table('ai_usage_counters')->insert(['day' => '20260102'] + $row);          // another day is fine

        $reservation = ['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'day' => '20260101', 'surface' => 'vendor', 'state' => 'open', 'created_at' => now()];
        DB::table('ai_budget_reservations')->insert($reservation);
        $this->assertThrows(fn () => DB::table('ai_budget_reservations')->insert($reservation), QueryException::class);

        $this->assertThrows(fn () => DB::table('ai_runtime_flags')->insert(['name' => 'kill_switch', 'value' => 'released', 'created_at' => now(), 'updated_at' => now()]), QueryException::class);
    }

    public function test_reapplying_it_changes_nothing_and_never_overrides_the_kill_switch(): void
    {
        $this->durableMigration()->up();
        DB::table('ai_runtime_flags')->where('name', 'kill_switch')->update(['value' => 'released', 'reason' => 'operator']);
        DB::table('ai_usage_counters')->insert(['day' => '20260101', 'scope' => 'global', 'requests' => 7, 'tokens' => 900, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_budget_reservations')->insert(['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'day' => '20260101', 'surface' => 'vendor', 'state' => 'settled', 'tokens' => 900, 'created_at' => now()]);

        $this->durableMigration()->up();
        $this->durableMigration()->up();

        $this->assertSame('released', DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('value'), 're-applying must not engage or release anything');
        $this->assertSame('operator', DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('reason'));
        $this->assertSame(1, DB::table('ai_runtime_flags')->count());
        $this->assertSame([7, 900], [(int) DB::table('ai_usage_counters')->value('requests'), (int) DB::table('ai_usage_counters')->value('tokens')], 'recorded usage is preserved');
        $this->assertSame(1, DB::table('ai_budget_reservations')->count());
        $this->assertSentinelIntact();
    }

    public function test_rolling_back_drops_only_these_tables_and_reapplying_starts_engaged_again(): void
    {
        $this->durableMigration()->up();
        DB::table('ai_runtime_flags')->where('name', 'kill_switch')->update(['value' => 'released']);

        $this->durableMigration()->down();

        foreach (self::DURABLE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} is gone");
        }
        $this->assertSentinelIntact();

        $this->durableMigration()->down(); // rolling back twice is harmless
        $this->durableMigration()->up();

        $this->assertSame('engaged', DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('value'), 'a re-created switch is fail-closed');
        $this->assertSentinelIntact();
    }

    public function test_the_framework_can_run_and_roll_back_exactly_this_migration(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Runs migrate against the throw-away in-memory SQLite database only; a shared database is covered by up()/down() above.');
        }

        $args = ['--path' => self::DURABLE_MIGRATION, '--realpath' => false, '--force' => true];

        $this->assertSame(0, Artisan::call('migrate', $args));
        foreach (self::DURABLE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame('engaged', DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('value'));

        $this->assertSame(0, Artisan::call('migrate', $args), 'a second run has nothing to do');
        $this->assertSame(1, DB::table('ai_runtime_flags')->count());

        $this->assertSame(0, Artisan::call('migrate:rollback', $args));
        foreach (self::DURABLE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} rolled back");
        }

        $this->assertSame(0, Artisan::call('migrate', $args), 're-applies cleanly after a rollback');
        $this->assertTrue(Schema::hasTable('ai_budget_reservations'));
        $this->assertSentinelIntact();
    }

    public function test_the_tables_are_transactional_on_mysql_and_mariadb(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL / MariaDB only.');
        }

        $this->durableMigration()->up();

        foreach (self::DURABLE_TABLES as $table) {
            $engine = DB::selectOne('SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->e;
            $this->assertSame('INNODB', strtoupper($engine), "{$table} needs row locks and transactions");
        }
    }
}
