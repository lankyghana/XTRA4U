<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utility Bills are a platform-owned service: the `orders` row that carries
 * their payment (and its integrity proof) belongs to no vendor. Vendor
 * attribution for commission lives on `utility_bill_orders.vendor_id`.
 *
 * Only the nullability changes. The foreign key, every existing value and every
 * existing order are untouched, and no existing code path creates an order
 * without a vendor, so no current behaviour moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_id')->nullable()->change();
        });
    }

    /**
     * Forward-only in production: tightening the column again would fail the
     * moment any platform-owned (vendor-less) order exists. Intentionally a no-op.
     */
    public function down(): void
    {
        //
    }
};
