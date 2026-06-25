<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendor_result_checker_settings', 'service_id')) {
            return;
        }

        // Existing rows have no service association and cannot be recovered — clear them.
        \DB::table('vendor_result_checker_settings')->truncate();

        Schema::table('vendor_result_checker_settings', function (Blueprint $table) {
            $table->foreignId('service_id')
                ->after('vendor_id')
                ->constrained('network_services')
                ->onDelete('cascade');

            $table->unique(['vendor_id', 'service_id']);
        });

        // Add composite index only if it doesn't already exist.
        $indexes = collect(\DB::select("SHOW INDEX FROM vendor_result_checker_settings"))
            ->pluck('Key_name')->unique()->values();

        if (!$indexes->contains('vendor_result_checker_settings_vendor_id_is_active_index')) {
            Schema::table('vendor_result_checker_settings', function (Blueprint $table) {
                $table->index(['vendor_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('vendor_result_checker_settings', 'service_id')) {
            return;
        }

        Schema::table('vendor_result_checker_settings', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'service_id']);
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
