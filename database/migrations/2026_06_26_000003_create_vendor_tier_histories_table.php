<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop if a previous failed run left a partial table.
        Schema::dropIfExists('vendor_tier_histories');

        Schema::create('vendor_tier_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('previous_tier_id')->nullable();
            $table->unsignedBigInteger('new_tier_id')->nullable();
            $table->enum('action', ['assigned', 'promoted', 'rejected', 'demoted', 'reset']);
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Plain indexes — no FK constraints to avoid engine mismatch issues
            // on the production MySQL server (vendors/admins may use MyISAM).
            $table->index('vendor_id');
            $table->index('previous_tier_id');
            $table->index('new_tier_id');
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_tier_histories');
    }
};
