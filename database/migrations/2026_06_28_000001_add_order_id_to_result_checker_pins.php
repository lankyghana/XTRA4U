<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The result_checker_pins table was originally created with `checker_type_id`
 * (old schema). Several subsequent migrations only made checker_type_id nullable
 * but never added the `result_checker_order_id` column that the
 * ResultCheckerService and ResultCheckerPin model require.
 *
 * This migration idempotently adds that column (and its FK + index) when absent.
 * Each step checks information_schema before applying, so it is safe to re-run
 * even after a partial failure (MySQL DDL is non-transactional and auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('result_checker_pins')) {
            return;
        }

        $db = DB::getDatabaseName();

        // Step 1: Add result_checker_order_id column if missing.
        if (!Schema::hasColumn('result_checker_pins', 'result_checker_order_id')) {
            DB::statement('ALTER TABLE result_checker_pins
                ADD COLUMN result_checker_order_id BIGINT UNSIGNED NULL DEFAULT NULL
                AFTER `serial`');
        }

        // Step 2: Add FK constraint only if it doesn't already exist.
        $hasFk = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', 'result_checker_pins')
            ->where('CONSTRAINT_NAME', 'result_checker_pins_order_id_foreign')
            ->exists();

        if (!$hasFk && Schema::hasTable('result_checker_orders')) {
            DB::statement('ALTER TABLE result_checker_pins
                ADD CONSTRAINT result_checker_pins_order_id_foreign
                FOREIGN KEY (result_checker_order_id)
                REFERENCES result_checker_orders (id)
                ON DELETE SET NULL');
        }

        // Step 3: Add index only if it doesn't already exist.
        $hasIndex = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', 'result_checker_pins')
            ->where('INDEX_NAME', 'result_checker_pins_order_id_index')
            ->exists();

        if (!$hasIndex) {
            DB::statement('ALTER TABLE result_checker_pins
                ADD INDEX result_checker_pins_order_id_index (result_checker_order_id)');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('result_checker_pins', 'result_checker_order_id')) {
            return;
        }

        Schema::table('result_checker_pins', function (Blueprint $table) {
            try {
                $table->dropForeign('result_checker_pins_order_id_foreign');
            } catch (\Throwable $e) {
                // FK didn't exist — ignore.
            }
            $table->dropColumn('result_checker_order_id');
        });
    }
};
