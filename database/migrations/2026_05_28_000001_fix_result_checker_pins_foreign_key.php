<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixResultCheckerPinsForeignKey extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('result_checker_pins')) {
            // Drop checker_type_id column if it exists
            if (Schema::hasColumn('result_checker_pins', 'checker_type_id')) {
                // First try to drop any foreign key that might exist
                try {
                    Schema::table('result_checker_pins', function (Blueprint $table) {
                        $table->dropForeign(['checker_type_id']);
                    });
                } catch (\Exception $e) {
                    Log::info('Could not drop foreign key for checker_type_id: ' . $e->getMessage());
                }

                // Then try to drop any index that might exist
                try {
                    Schema::table('result_checker_pins', function (Blueprint $table) {
                        $table->dropIndex(['checker_type_id']);
                    });
                } catch (\Exception $e) {
                    Log::info('Could not drop index for checker_type_id: ' . $e->getMessage());
                }

                // Finally try to drop the column
                try {
                    Schema::table('result_checker_pins', function (Blueprint $table) {
                        $table->dropColumn('checker_type_id');
                    });
                } catch (\Exception $e) {
                    Log::info('Could not drop column checker_type_id via Blueprint: ' . $e->getMessage());
                    
                    try {
                        DB::statement('ALTER TABLE result_checker_pins DROP COLUMN checker_type_id');
                    } catch (\Exception $e2) {
                        Log::info('Could not drop column checker_type_id via raw query: ' . $e2->getMessage());
                    }
                }
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
