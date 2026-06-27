<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * result_checker_orders has a legacy checker_type_id column (NOT NULL, no default)
     * left over from the original schema. The same fix was already applied to
     * result_checker_pins (2026_06_25_000001) and vendor_result_checker_settings
     * (2026_06_26_000001). Use raw SQL to reliably make it nullable on MySQL so
     * INSERT without this column no longer fails.
     */
    public function up(): void
    {
        if (Schema::hasColumn('result_checker_orders', 'checker_type_id')) {
            DB::statement('ALTER TABLE result_checker_orders MODIFY COLUMN checker_type_id BIGINT UNSIGNED NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        // Intentionally left as a no-op: reverting to NOT NULL would re-break inserts.
    }
};
