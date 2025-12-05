<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL use raw SQL for ENUM, for SQLite use string type
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('Pending', 'Processing', 'Completed', 'Failed', 'Cancelled') NOT NULL DEFAULT 'Pending'");
        } else {
            // SQLite doesn't support ENUM, use string instead
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status')->default('Pending')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('Processing', 'Completed', 'Failed') NOT NULL DEFAULT 'Processing'");
        } else {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status')->default('Processing')->change();
            });
        }
    }
};
