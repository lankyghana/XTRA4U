<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: two nullable timestamps plus one index used by the status
 * poller. Nothing existing is altered, so this is safe to run against a live
 * orders table and safe to roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'external_fulfillment_delivered_at')) {
                // When the provider confirmed delivery, as opposed to
                // external_fulfillment_completed_at which marks when we stopped
                // acting on the order (accepted, delivered or failed).
                $table->timestamp('external_fulfillment_delivered_at')->nullable();
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_last_status_check_at')) {
                // Poller bookkeeping: throttles how often one order is re-checked.
                $table->timestamp('external_fulfillment_last_status_check_at')->nullable();
            }
        });

        // Supports the poller's "in-flight orders for this provider" lookup.
        if (! $this->indexExists('orders', 'orders_ext_fulfillment_sync_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(
                    ['external_fulfillment_status', 'status'],
                    'orders_ext_fulfillment_sync_index'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('orders', 'orders_ext_fulfillment_sync_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_ext_fulfillment_sync_index');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'external_fulfillment_delivered_at',
                'external_fulfillment_last_status_check_at',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return Schema::getConnection()
                ->getSchemaBuilder()
                ->getIndexes($table) !== []
                && collect(Schema::getConnection()->getSchemaBuilder()->getIndexes($table))
                    ->contains(fn ($definition) => ($definition['name'] ?? null) === $index);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
