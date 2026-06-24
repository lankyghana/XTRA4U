<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_checker_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('result_checker_orders', 'service_id')) {
                $table->unsignedBigInteger('service_id')->nullable()->after('vendor_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('result_checker_orders', function (Blueprint $table) {
            if (Schema::hasColumn('result_checker_orders', 'service_id')) {
                $table->dropColumn('service_id');
            }
        });
    }
};