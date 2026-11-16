<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purely additive: two nullable columns. Existing rows and every existing code
 * path are unaffected (they never read or write these). No FK on purpose — the
 * production `orders` table engine has historically not supported them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('developer_application_id')->nullable()->index();
            $table->string('developer_order_ref', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['developer_order_ref']);
            $table->dropIndex(['developer_application_id']);
            $table->dropColumn(['developer_application_id', 'developer_order_ref']);
        });
    }
};
