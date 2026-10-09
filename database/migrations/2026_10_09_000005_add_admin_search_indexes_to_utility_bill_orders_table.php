<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive indexes for the Admin Utility Bill Sales search. The default
 * reference/account search is now a "starts with" match on five columns; the
 * three reference columns already have unique indexes, and these two make the
 * account columns index range reads too, so MySQL answers the OR with an index
 * merge instead of scanning the table (verified with EXPLAIN on a 200k-sale /
 * 1M-order synthetic dataset: about 1.6 s -> under 10 ms).
 *
 * (fulfillment_status, id) and (vendor_id, id) were also tried and dropped:
 * the optimizer kept the existing indexes and they measured no faster.
 *
 * Index-only change: no data is touched.
 */
return new class extends Migration
{
    private const INDEXES = [
        'ubo_account_number_idx' => ['account_number'],
        'ubo_account_name_idx' => ['account_name'],
    ];

    public function up(): void
    {
        Schema::table('utility_bill_orders', function (Blueprint $table) {
            foreach (self::INDEXES as $name => $columns) {
                $table->index($columns, $name);
            }
        });
    }

    public function down(): void
    {
        Schema::table('utility_bill_orders', function (Blueprint $table) {
            foreach (array_keys(self::INDEXES) as $name) {
                $table->dropIndex($name);
            }
        });
    }
};
