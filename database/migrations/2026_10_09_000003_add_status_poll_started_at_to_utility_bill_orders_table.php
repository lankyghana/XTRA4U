<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: when the current automatic status-polling window started (set when
 * the provider accepts a submission, and again if an admin resumes polling for
 * an unresolved order). Bounds automatic polling by time as well as by count.
 * Existing rows keep NULL and fall back to submitted_at; nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_bill_orders', function (Blueprint $table) {
            $table->timestamp('status_poll_started_at')->nullable()->after('next_status_check_at');
        });
    }

    public function down(): void
    {
        Schema::table('utility_bill_orders', function (Blueprint $table) {
            $table->dropColumn('status_poll_started_at');
        });
    }
};
