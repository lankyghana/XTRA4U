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
        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_name')->unique(); // paystack, flutterwave, momo, bulkclix
            $table->string('gateway_type'); // payment_collection, payout, sms
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('config_data'); // encrypted JSON data for API keys, URLs, etc.
            $table->json('supported_features')->nullable(); // features like cards, mobile_money, bank_transfer
            $table->string('environment')->default('sandbox'); // sandbox, live
            $table->timestamps();

            // Ensure only one default gateway per type
            $table->index(['gateway_type', 'is_default']);
            $table->index(['gateway_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_configs');
    }
};