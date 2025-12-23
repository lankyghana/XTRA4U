<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hubtel collection + SMS are not wired into the current contracts/services.
        // Keep these flags false to prevent accidental activation/defaulting.
        DB::table('payment_gateway_configs')
            ->where('gateway_name', 'hubtel')
            ->update([
                'supports_collection' => false,
                'supports_generic' => false,
                'supports_sms' => false,
            ]);
    }

    public function down(): void
    {
        // No-op: we intentionally do not restore unsafe flags.
    }
};
