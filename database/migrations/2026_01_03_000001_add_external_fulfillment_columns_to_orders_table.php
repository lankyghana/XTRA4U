<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'external_fulfillment_status')) {
                $table->string('external_fulfillment_status')->nullable();
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_idempotency_key')) {
                $table->string('external_fulfillment_idempotency_key')->nullable();
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_attempts')) {
                $table->unsignedInteger('external_fulfillment_attempts')->default(0);
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_last_attempt_at')) {
                $table->timestamp('external_fulfillment_last_attempt_at')->nullable();
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_completed_at')) {
                $table->timestamp('external_fulfillment_completed_at')->nullable();
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_remote_reference')) {
                $table->string('external_fulfillment_remote_reference')->nullable();
            }

            if (! Schema::hasColumn('orders', 'external_fulfillment_last_error')) {
                $table->text('external_fulfillment_last_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'external_fulfillment_status',
                'external_fulfillment_idempotency_key',
                'external_fulfillment_attempts',
                'external_fulfillment_last_attempt_at',
                'external_fulfillment_completed_at',
                'external_fulfillment_remote_reference',
                'external_fulfillment_last_error',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
