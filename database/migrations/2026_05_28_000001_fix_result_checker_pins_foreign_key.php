<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixResultCheckerPinsForeignKey extends Migration
{
    public function up(): void
    {
        // Check if result_checker_pins table exists and has the wrong column
        if (Schema::hasTable('result_checker_pins')) {
            Schema::table('result_checker_pins', function (Blueprint $table) {
                // Drop the incorrect foreign key if it exists
                if (Schema::hasColumn('result_checker_pins', 'checker_type_id')) {
                    try {
                        $table->dropForeign(['checker_type_id']);
                    } catch (\Exception $e) {
                        // Foreign key might not exist
                    }
                    $table->dropColumn('checker_type_id');
                }
                
                // Ensure service_id exists as foreign key
                if (!Schema::hasColumn('result_checker_pins', 'service_id')) {
                    $table->foreignId('service_id')
                        ->constrained('network_services')
                        ->onDelete('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        // This migration only fixes the schema, so no down is needed
    }
}
