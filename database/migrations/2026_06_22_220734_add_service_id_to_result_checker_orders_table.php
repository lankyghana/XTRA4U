<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('result_checker_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->after('vendor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('result_checker_orders', function (Blueprint $table) {
            $table->dropColumn('service_id');
        });
    }
};