<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_gateway_configs', 'supports_webhook')) {
                $table->boolean('supports_webhook')->default(false)->after('supports_sms');
            }
        });

        // Safe default for all existing gateways.
        DB::table('payment_gateway_configs')->update([
            'supports_webhook' => false,
        ]);

        // Enable for Moolre rows if they already exist.
        DB::table('payment_gateway_configs')->where('gateway_name', 'moolre')->update([
            'supports_webhook' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table) {
            if (Schema::hasColumn('payment_gateway_configs', 'supports_webhook')) {
                $table->dropColumn('supports_webhook');
            }
        });
    }
};
