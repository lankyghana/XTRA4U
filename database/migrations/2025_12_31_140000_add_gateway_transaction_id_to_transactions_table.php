<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'gateway_transaction_id')) {
                $table->string('gateway_transaction_id', 120)->nullable()->after('payment_status');
            }
        });

        try {
            Schema::table('transactions', function (Blueprint $table) {
                $table->index(['gateway_transaction_id'], 'transactions_gateway_transaction_id_idx');
            });
        } catch (\Throwable $e) {
            // ignore (index may already exist)
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            try {
                $table->dropIndex('transactions_gateway_transaction_id_idx');
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('transactions', 'gateway_transaction_id')) {
                $table->dropColumn('gateway_transaction_id');
            }
        });
    }
};
