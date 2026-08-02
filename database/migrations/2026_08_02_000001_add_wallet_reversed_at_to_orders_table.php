<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks when an order's vendor earnings were reversed (e.g. on refund),
     * so a status change into "Refunded" only ever deducts vendor wallets once,
     * even if the order is later moved out of and back into "Refunded".
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('wallet_reversed_at')->nullable()->after('payment_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('wallet_reversed_at');
        });
    }
};
