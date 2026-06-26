<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_tier_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('previous_tier_id')->nullable()->constrained('vendor_tiers')->nullOnDelete();
            $table->foreignId('new_tier_id')->nullable()->constrained('vendor_tiers')->nullOnDelete();
            $table->enum('action', ['assigned', 'promoted', 'rejected', 'demoted', 'reset']);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_tier_histories');
    }
};
