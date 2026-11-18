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

                // Explicit name: the auto-generated one ("result_checker_pricing_tiers_network_service_id_min_quantity_
                // max_quantity_unique") is 80 characters, over the 64 limit of MySQL/MariaDB, so a fresh install failed.
                // Installs that already ran this migration keep their old index; nothing refers to it by name.
                $table->unique(['network_service_id', 'min_quantity', 'max_quantity'], 'rc_pricing_tiers_service_qty_unique');
                $table->foreign('network_service_id')->references('id')->on('network_services')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('result_checker_pricing_tiers');
    }
};
