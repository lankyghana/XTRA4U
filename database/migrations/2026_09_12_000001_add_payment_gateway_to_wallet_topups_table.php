<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which collection gateway (paystack|flutterwave|bulkclix|moolre|
     * payaza — same gateway_name values as payment_gateway_configs and the
     * orders/afa_registrations/result_checker_orders/ussd_subscriptions
     * tables) actually created a wallet top-up, so it can always be verified
     * against the gateway that originated it rather than whichever gateway
     * is currently the platform default.
     *
     * Nullable: rows created before this column existed have no reliable way
     * to determine their original gateway after the fact (see the Phase 2
     * reconciliation-hardening report) and are left NULL deliberately rather
     * than guessed.
     */
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->string('payment_gateway')->nullable()->after('status');
            $table->index('payment_gateway');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->dropIndex(['payment_gateway']);
            $table->dropColumn('payment_gateway');
        });
    }
};
