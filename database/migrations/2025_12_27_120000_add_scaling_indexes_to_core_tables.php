<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orders: support vendor dashboards, admin listings, and webhook lookup.
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_reference')) {
                $table->index(['payment_reference'], 'orders_payment_reference_idx');
            }

            if (Schema::hasColumns('orders', ['vendor_id', 'payment_status', 'status', 'created_at'])) {
                $table->index(['vendor_id', 'payment_status', 'status', 'created_at'], 'orders_vendor_pay_status_created_idx');
            }

            if (Schema::hasColumns('orders', ['owner_vendor_id', 'payment_status', 'status', 'created_at'])) {
                $table->index(['owner_vendor_id', 'payment_status', 'status', 'created_at'], 'orders_owner_pay_status_created_idx');
            }

            if (Schema::hasColumns('orders', ['reseller_vendor_id', 'payment_status', 'status', 'created_at'])) {
                $table->index(['reseller_vendor_id', 'payment_status', 'status', 'created_at'], 'orders_reseller_pay_status_created_idx');
            }
        });

        // Transactions: support vendor dashboards and vendor analytics.
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumns('transactions', ['vendor_id', 'payment_status', 'created_at'])) {
                $table->index(['vendor_id', 'payment_status', 'created_at'], 'tx_vendor_status_created_idx');
            }

            if (Schema::hasColumns('transactions', ['order_id', 'vendor_id'])) {
                $table->index(['order_id', 'vendor_id'], 'tx_order_vendor_idx');
            }
        });

        // Vendor withdrawals: support vendor history + retry scans.
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            if (Schema::hasColumns('vendor_withdrawals', ['vendor_id', 'status', 'created_at'])) {
                $table->index(['vendor_id', 'status', 'created_at'], 'vw_vendor_status_created_idx');
            }

            if (Schema::hasColumns('vendor_withdrawals', ['status', 'payout_attempted_at'])) {
                $table->index(['status', 'payout_attempted_at'], 'vw_status_attempted_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_payment_reference_idx');
            $table->dropIndex('orders_vendor_pay_status_created_idx');
            $table->dropIndex('orders_owner_pay_status_created_idx');
            $table->dropIndex('orders_reseller_pay_status_created_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('tx_vendor_status_created_idx');
            $table->dropIndex('tx_order_vendor_idx');
        });

        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $table->dropIndex('vw_vendor_status_created_idx');
            $table->dropIndex('vw_status_attempted_idx');
        });
    }
};
