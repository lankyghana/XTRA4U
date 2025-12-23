<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $table->string('payout_gateway')->nullable()->after('momo_network');
            $table->timestamp('payout_attempted_at')->nullable()->after('payout_transaction_id');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->text('error_message')->nullable()->after('notes');
        });

        // Backfill legacy statuses to the new simplified lifecycle.
        DB::table('vendor_withdrawals')->where('status', 'pending')->update([
            'status' => 'processing',
        ]);

        DB::table('vendor_withdrawals')->where('status', 'rejected')->update([
            'status' => 'failed',
            'payout_status' => DB::raw("COALESCE(payout_status, 'failed')"),
            'error_message' => DB::raw("COALESCE(error_message, 'Legacy rejected withdrawal')"),
        ]);

        // Normalize legacy approved withdrawals to have a completed payout_status.
        DB::table('vendor_withdrawals')->where('status', 'approved')->whereNull('payout_status')->update([
            'payout_status' => 'completed',
        ]);
    }

    public function down(): void
    {
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['payout_gateway', 'payout_attempted_at', 'refunded_at', 'error_message']);
        });
    }
};
