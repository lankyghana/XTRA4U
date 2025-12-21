<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add status column with proper states
            $table->enum('status', ['pending', 'successful', 'failed', 'abandoned'])
                ->default('pending')
                ->after('payment_status');
            
            // Add reference for idempotency
            $table->string('payment_reference')->nullable()->after('status')->index();
            
            // Add verified_at timestamp
            $table->timestamp('verified_at')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['status', 'payment_reference', 'verified_at']);
        });
    }
};
