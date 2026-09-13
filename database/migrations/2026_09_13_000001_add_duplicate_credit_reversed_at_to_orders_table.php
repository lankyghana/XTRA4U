<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable per-order idempotency marker for
     * `payments:reverse-legacy-duplicate-credits`, deliberately separate from
     * `wallet_reversed_at` (which tracks refund reversals): an order this
     * command has already reversed must never be double-reversed by a second
     * run, and a later legitimate refund on the same order must still be free
     * to use `wallet_reversed_at`/`WalletService::reverseOrderEarnings()`
     * without being blocked or confused by this incident-specific marker.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('duplicate_credit_reversed_at')->nullable()->after('wallet_reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('duplicate_credit_reversed_at');
        });
    }
};
