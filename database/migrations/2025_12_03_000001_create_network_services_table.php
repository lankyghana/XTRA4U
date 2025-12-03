<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('category', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_services');
    }
};
