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
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $table->string('momo_number', 15)->after('amount');
            $table->enum('momo_network', ['MTN', 'TELECEL', 'AirtelTigo'])->after('momo_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['momo_number', 'momo_network']);
        });
    }
};
