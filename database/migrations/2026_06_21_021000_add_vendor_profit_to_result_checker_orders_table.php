<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_checker_orders', function (Blueprint $table) {
            $table->decimal('vendor_profit', 10, 2)->default(0)->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('result_checker_orders', function (Blueprint $table) {
            $table->dropColumn('vendor_profit');
        });
    }
};
