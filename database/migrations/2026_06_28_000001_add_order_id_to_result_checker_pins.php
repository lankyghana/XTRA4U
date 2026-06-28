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
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('result_checker_pins')) {
            return; // Table doesn't exist — nothing to do.
        }

        // Add result_checker_order_id if it is missing.
        if (!Schema::hasColumn('result_checker_pins', 'result_checker_order_id')) {
            // Use raw DDL so we can add the nullable FK column and its index
            // without Blueprint guessing the wrong constraint name.
            DB::statement('ALTER TABLE result_checker_pins
                ADD COLUMN result_checker_order_id BIGINT UNSIGNED NULL DEFAULT NULL
                AFTER `serial`');

            // Add FK constraint (result_checker_orders must already exist).
            if (Schema::hasTable('result_checker_orders')) {
                DB::statement('ALTER TABLE result_checker_pins
                    ADD CONSTRAINT result_checker_pins_order_id_foreign
                    FOREIGN KEY (result_checker_order_id)
                    REFERENCES result_checker_orders (id)
                    ON DELETE SET NULL');
            }

            // Add index to support the WHERE clause used in doAllocatePins / doReleasePins.
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
            // Drop FK first, then the index and column.
            try {
                $table->dropForeign('result_checker_pins_order_id_foreign');
            } catch (\Throwable $e) {
                // Ignore if FK didn't exist.
            }
            $table->dropColumn('result_checker_order_id');
        });
    }
};
