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
            $table->string('payout_reference')->nullable()->after('reference');
            $table->string('payout_status')->nullable()->after('payout_reference');
            $table->timestamp('paid_at')->nullable()->after('payout_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['payout_reference', 'payout_status', 'paid_at']);
        });
    }
};
