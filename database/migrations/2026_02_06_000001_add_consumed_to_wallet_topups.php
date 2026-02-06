<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->decimal('consumed', 12, 2)->default(0.00)->after('amount');
        });

        // Create index to support efficient available-topups queries
        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->index(['vendor_id', 'status', 'consumed'], 'idx_wallet_topups_available');
        });

        // Backfill consumed from metadata if present
        DB::table('wallet_topups')->select('id', 'metadata')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $r) {
                $consumed = 0.00;
                if (! empty($r->metadata)) {
                    try {
                        $meta = json_decode($r->metadata, true);
                        if (is_array($meta) && isset($meta['consumed'])) {
                            $consumed = (float) $meta['consumed'];
                        }
                    } catch (\Throwable $e) {
                        // ignore malformed metadata
                    }
                }
                DB::table('wallet_topups')->where('id', $r->id)->update(['consumed' => $consumed]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->dropIndex('idx_wallet_topups_available');
            $table->dropColumn('consumed');
        });
    }
};
