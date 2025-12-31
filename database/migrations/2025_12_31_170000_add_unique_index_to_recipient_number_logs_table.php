<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up legacy rows that would violate uniqueness.
        // This keeps the most recent row (highest id) per (phone_number, vendor_id, service_type).
        // Also remove rows with NULL vendor_id since new writes are vendor-scoped.
        try {
            DB::table('recipient_number_logs')->whereNull('vendor_id')->delete();

            DB::table('recipient_number_logs')
                ->select('phone_number', 'vendor_id', 'service_type', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as c'))
                ->groupBy('phone_number', 'vendor_id', 'service_type')
                ->having('c', '>', 1)
                ->orderBy('keep_id')
                ->chunk(1000, function ($groups) {
                    foreach ($groups as $g) {
                        DB::table('recipient_number_logs')
                            ->where('phone_number', $g->phone_number)
                            ->where('vendor_id', $g->vendor_id)
                            ->where('service_type', $g->service_type)
                            ->where('id', '<>', $g->keep_id)
                            ->delete();
                    }
                });
        } catch (Throwable $e) {
            // Best-effort cleanup; index creation below is the real enforcement.
        }

        Schema::table('recipient_number_logs', function (Blueprint $table) {
            $table->unique(['phone_number', 'vendor_id', 'service_type'], 'recipient_number_logs_phone_vendor_service_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recipient_number_logs', function (Blueprint $table) {
            $table->dropUnique('recipient_number_logs_phone_vendor_service_unique');
        });
    }
};
