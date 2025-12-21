<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mark historical completed payments as successful transactions
        DB::table('transactions')
            ->where('status', 'pending')
            ->whereIn('payment_status', ['completed', 'paid'])
            ->update([
                'status' => 'successful',
                'verified_at' => DB::raw('COALESCE(verified_at, updated_at, created_at)'),
            ]);

        // Mark any explicitly failed payouts
        DB::table('transactions')
            ->where('status', 'pending')
            ->whereIn('payment_status', ['failed', 'cancelled', 'canceled'])
            ->update(['status' => 'failed']);

        // Everything else stays pending until verified
    }

    public function down(): void
    {
        DB::table('transactions')
            ->whereIn('status', ['successful', 'failed'])
            ->update(['status' => 'pending']);
    }
};
