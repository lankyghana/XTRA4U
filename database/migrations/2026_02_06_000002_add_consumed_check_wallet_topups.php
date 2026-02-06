<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add a DB-level check constraint to ensure consumed <= amount where supported.
        try {
            DB::statement('ALTER TABLE wallet_topups ADD CONSTRAINT chk_wallet_topups_consumed_amount CHECK (consumed <= amount)');
        } catch (\Throwable $e) {
            // Some MySQL versions / engines may ignore CHECK constraints; log and continue.
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE wallet_topups DROP CONSTRAINT chk_wallet_topups_consumed_amount');
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
