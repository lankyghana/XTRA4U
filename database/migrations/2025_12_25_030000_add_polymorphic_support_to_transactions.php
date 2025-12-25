<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add polymorphic columns for flexible transaction ownership
            $table->string('transactionable_type')->nullable()->after('id');
            $table->unsignedBigInteger('transactionable_id')->nullable()->after('transactionable_type');
            
            // Add index for polymorphic relationship
            $table->index(['transactionable_type', 'transactionable_id'], 'transactions_transactionable_index');
            
            // Add payment_type for easier querying and reporting
            $table->string('payment_type')->nullable()->after('payment_status')
                ->comment('order, afa_registration, etc.');
        });

        // Backfill existing transactions: set type to Order and copy order_id to transactionable_id
        DB::table('transactions')
            ->whereNotNull('order_id')
            ->update([
                'transactionable_type' => 'App\\Models\\Order',
                'transactionable_id' => DB::raw('order_id'),
                'payment_type' => 'order',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_transactionable_index');
            $table->dropColumn(['transactionable_type', 'transactionable_id', 'payment_type']);
        });
    }
};
