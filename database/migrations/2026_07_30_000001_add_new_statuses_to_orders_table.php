<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Refunded, On Hold, and Verifying to the orders status ENUM.
     *
     * This is a safe ALTER on production MySQL: extending an ENUM does NOT
     * rebuild the table and requires no downtime.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE orders MODIFY COLUMN status
                 ENUM('Pending','Processing','Completed','Failed','Cancelled','Refunded','On Hold','Verifying')
                 NOT NULL DEFAULT 'Pending'"
            );
        } else {
            // SQLite (used in tests) does not support ENUM — the column is already
            // a plain string there, so no structural change is needed.
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status')->default('Pending')->change();
            });
        }
    }

    /**
     * Reverse the migration — removes the three new statuses from the ENUM.
     *
     * NOTE: If any rows already have the new status values this will fail.
     * Update those rows manually before rolling back.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE orders MODIFY COLUMN status
                 ENUM('Pending','Processing','Completed','Failed','Cancelled')
                 NOT NULL DEFAULT 'Pending'"
            );
        } else {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status')->default('Pending')->change();
            });
        }
    }
};
