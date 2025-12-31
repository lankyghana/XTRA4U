<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'downloaded_at')) {
                $table->timestamp('downloaded_at')->nullable()->after('payment_completed_at');
            }

            // Index-friendly scans for "available" vs "downloaded" fulfillment queues.
            if (Schema::hasColumn('orders', 'downloaded_at')) {
                $table->index(['downloaded_at'], 'orders_downloaded_at_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Safely drop index/column if they exist.
            try {
                $table->dropIndex('orders_downloaded_at_idx');
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('orders', 'downloaded_at')) {
                $table->dropColumn('downloaded_at');
            }
        });
    }
};
