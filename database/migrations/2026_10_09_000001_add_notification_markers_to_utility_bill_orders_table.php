<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic "send once" markers for Utility Bill side effects (customer SMS,
 * terminal alert, stuck alert). Each is claimed with a conditional
 * `UPDATE ... WHERE <col> IS NULL`, so two concurrent callers can never both
 * send. Previously these were "does an event exist?" checks, which race.
 *
 * Backfilled from the existing timeline events so nothing already sent is
 * sent again.
 */
return new class extends Migration
{
    private const MARKERS = [
        'customer_notified_at' => 'customer_notified',
        'terminal_alerted_at' => 'terminal_alert',
        'stuck_alerted_at' => 'stuck_alert',
    ];

    public function up(): void
    {
        Schema::table('utility_bill_orders', function (Blueprint $table) {
            $table->timestamp('customer_notified_at')->nullable()->after('fulfilled_at');
            $table->timestamp('terminal_alerted_at')->nullable()->after('customer_notified_at');
            $table->timestamp('stuck_alerted_at')->nullable()->after('terminal_alerted_at');
        });

        foreach (self::MARKERS as $column => $kind) {
            DB::table('utility_bill_orders')
                ->whereIn('id', DB::table('utility_bill_events')->where('kind', $kind)->select('utility_bill_order_id'))
                ->update([$column => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('utility_bill_orders', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::MARKERS));
        });
    }
};
