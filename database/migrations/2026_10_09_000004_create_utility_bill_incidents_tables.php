<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: provider-level operational incidents for Utility Bills, so an
 * outage produces one actionable admin alert instead of one per stuck order.
 *
 * At most ONE active incident per (provider, category, scope): `active_key` is
 * 'active' while it is open and NULL once recovered, and the unique index on it
 * makes "open or join the active incident" atomic across processes (NULLs never
 * collide, so recovered history accumulates freely).
 *
 * Orders keep their own status, error and event history; the pivot only records
 * which orders an incident affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_bill_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('kingflexy');
            $table->string('category', 40);
            $table->string('scope', 60)->default('');
            $table->string('active_key', 10)->nullable();
            $table->string('state', 12)->default('active');
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('occurrences')->default(0);
            $table->unsignedInteger('affected_orders')->default(0);
            $table->string('last_detail', 255)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->unsignedInteger('alerts_sent')->default(0);
            $table->timestamp('recovered_at')->nullable();
            $table->string('recovery_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'category', 'scope', 'active_key'], 'ubi_one_active_uq');
            $table->index(['state', 'last_detected_at'], 'ubi_state_idx');
        });

        Schema::create('utility_bill_incident_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('utility_bill_incident_id');
            $table->unsignedBigInteger('utility_bill_order_id');
            $table->timestamp('first_seen_at');

            $table->primary(['utility_bill_incident_id', 'utility_bill_order_id'], 'ubio_pk');
            $table->index('utility_bill_order_id', 'ubio_order_idx');
            $table->foreign('utility_bill_incident_id', 'ubio_incident_fk')->references('id')->on('utility_bill_incidents')->cascadeOnDelete();
            $table->foreign('utility_bill_order_id', 'ubio_order_fk')->references('id')->on('utility_bill_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_bill_incident_orders');
        Schema::dropIfExists('utility_bill_incidents');
    }
};
