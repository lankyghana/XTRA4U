<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('result_checker_pricing_tiers')) {
            Schema::create('result_checker_pricing_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('network_service_id');
                $table->integer('min_quantity')->default(1);
                $table->integer('max_quantity')->nullable();
                $table->decimal('price', 8, 2);
                $table->timestamps();

                $table->unique(['network_service_id', 'min_quantity', 'max_quantity']);
                $table->foreign('network_service_id')->references('id')->on('network_services')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('result_checker_pricing_tiers');
    }
};
