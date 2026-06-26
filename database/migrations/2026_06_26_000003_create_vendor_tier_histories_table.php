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

            $table->index('vendor_id');
            $table->index('previous_tier_id');
            $table->index('new_tier_id');
            $table->index('admin_id');
        });

        // Add FK constraints separately using the explicit pattern that is
        // proven to work on this MySQL server (same as affiliate_vendor_id FK).
        Schema::table('vendor_tier_histories', function (Blueprint $table) {
            $table->foreign('vendor_id')
                ->references('id')->on('vendors')
                ->onDelete('cascade');

            $table->foreign('previous_tier_id')
                ->references('id')->on('vendor_tiers')
                ->onDelete('set null');

            $table->foreign('new_tier_id')
                ->references('id')->on('vendor_tiers')
                ->onDelete('set null');

            $table->foreign('admin_id')
                ->references('id')->on('admins')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_tier_histories', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropForeign(['previous_tier_id']);
            $table->dropForeign(['new_tier_id']);
            $table->dropForeign(['admin_id']);
        });

        Schema::dropIfExists('vendor_tier_histories');
    }
};
