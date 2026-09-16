<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve product history for financial auditing.
 *
 * A product row carries the only durable record of WHAT a historical order
 * bought: the external provider service id it maps to, its network, its
 * capacity, and the price the main vendor had set. Deleting one used to remove
 * that permanently — orders survived (their `vendor_service_id` FK is
 * `nullOnDelete`) but were left pointing at nothing, which is why auditing
 * historical low-priced orders back to the service they were for was not
 * possible.
 *
 * Soft deletion keeps the evidence while removing the product from sale: the
 * SoftDeletes global scope excludes it from every storefront, marketplace and
 * vendor listing query automatically, and Product::deleting() also flips
 * `is_active` to false so even a query that only filters on that column cannot
 * surface it.
 *
 * Historical orders do not depend on this to stay correct — each one now
 * freezes its own immutable financial snapshot at creation — but being able to
 * look up the product an order referred to is what makes an incident
 * investigable at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'deleted_at')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'deleted_at')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
