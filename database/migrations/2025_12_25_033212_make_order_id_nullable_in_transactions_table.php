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
        Schema::table('transactions', function (Blueprint $table) {
            // Make order_id nullable to support polymorphic transactions
            // that may belong to AfaRegistration instead of Order
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Note: This assumes all transactions will have an order_id when rolling back
            // May need manual data cleanup before rollback
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
    }
};
