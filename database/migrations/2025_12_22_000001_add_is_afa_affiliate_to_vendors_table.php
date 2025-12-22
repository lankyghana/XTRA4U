<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('is_afa_affiliate')->default(false);
        });

        // Backfill: any existing affiliate (reseller) must also be an AFA affiliate.
        DB::table('vendors')
            ->whereNotNull('affiliate_vendor_id')
            ->update(['is_afa_affiliate' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('is_afa_affiliate');
        });
    }
};
