<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing orders to have Pending status if they're Processing
        DB::table('orders')->where('status', 'Processing')->update(['status' => 'Pending']);
        
        // For MySQL use raw SQL, for SQLite just change to string (SQLite doesn't support ENUM)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('Pending', 'Processing', 'Completed', 'Failed') DEFAULT 'Pending'");
        } else {
            // SQLite: Change column to string type (Laravel handles this gracefully)
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
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('Processing', 'Completed', 'Failed') DEFAULT 'Processing'");
        } else {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status')->default('Processing')->change();
            });
        }
    }
};
