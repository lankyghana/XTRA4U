<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'affiliate_chain_snapshot')) {
                $table->json('affiliate_chain_snapshot')->nullable()->after('platform_commission');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'affiliate_chain_snapshot')) {
                $table->dropColumn('affiliate_chain_snapshot');
            }
        });
    }
};
