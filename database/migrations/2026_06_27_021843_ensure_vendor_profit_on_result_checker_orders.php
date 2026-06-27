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
        if (! Schema::hasColumn('result_checker_orders', 'vendor_profit')) {
            Schema::table('result_checker_orders', function (Blueprint $table) {
                $table->decimal('vendor_profit', 10, 2)->default(0)->after('total_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('result_checker_orders', 'vendor_profit')) {
            Schema::table('result_checker_orders', function (Blueprint $table) {
                $table->dropColumn('vendor_profit');
            });
        }
    }
};
