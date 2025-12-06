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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->default('pending')->after('status');
            $table->string('payment_reference')->nullable()->after('payment_status');
            $table->string('payment_gateway')->nullable()->default('moolre')->after('payment_reference');
            $table->timestamp('payment_completed_at')->nullable()->after('payment_gateway');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'payment_reference', 'payment_gateway', 'payment_completed_at']);
        });
    }
};
