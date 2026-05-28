<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixResultCheckerPinsForeignKey extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('result_checker_pins')) {
            // Drop checker_type_id column if it exists
            if (Schema::hasColumn('result_checker_pins', 'checker_type_id')) {
                DB::statement('ALTER TABLE result_checker_pins DROP COLUMN checker_type_id');
            }

            // Ensure service_id exists
            if (!Schema::hasColumn('result_checker_pins', 'service_id')) {
                Schema::table('result_checker_pins', function (Blueprint $table) {
                    $table->foreignId('service_id')
                        ->constrained('network_services')
                        ->onDelete('cascade');
                });
            }
        }
    }

    public function down(): void
    {
        // This migration only fixes the schema, so no down is needed
    }
}
