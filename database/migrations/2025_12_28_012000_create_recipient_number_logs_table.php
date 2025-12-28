<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_number_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 32);
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('service_type', 80);
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index('phone_number');
            $table->index('vendor_id');
            $table->index('order_id');
            $table->index('service_type');
            $table->index('used_at');

            // Common admin query patterns (filter + date range)
            $table->index(['service_type', 'used_at']);
            $table->index(['vendor_id', 'used_at']);
            $table->index(['phone_number', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_number_logs');
    }
};
