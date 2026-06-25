<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The earlier fix_result_checker_pins_foreign_key migration silently failed on MySQL
        // because Blueprint could not guess the FK constraint name. Use raw SQL to reliably
        // make checker_type_id nullable so INSERTs without this column succeed.
        if (Schema::hasColumn('result_checker_pins', 'checker_type_id')) {
            DB::statement('ALTER TABLE result_checker_pins MODIFY COLUMN checker_type_id BIGINT UNSIGNED NULL DEFAULT NULL');
        }
    }

    public function down(): void {}
};
