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
        if (!Schema::hasTable('result_checker_pricing_tiers')) {
            Schema::create('result_checker_pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_service_id')->constrained('network_services')->onDelete('cascade');
            $table->integer('min_quantity')->default(1);
            $table->integer('max_quantity')->nullable();
            $table->decimal('price', 8, 2);
            $table->timestamps();

            $table->unique(['network_service_id', 'min_quantity', 'max_quantity']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('result_checker_pricing_tiers');
    }
};
