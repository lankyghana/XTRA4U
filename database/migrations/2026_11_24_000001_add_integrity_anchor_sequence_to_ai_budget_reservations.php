<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| M5.7.2: the sequence number the INDEPENDENT integrity anchor assigned to each reservation.
|
| The ledger row remembers `anchor_seq`; the anchor remembers the same number and the highest one it ever issued. A database
| that has moved backwards (an older backup, a point-in-time recovery, deleted rows) is then recognisable by comparing the
| highest `anchor_seq` it still holds with the anchor's. NULL = the reservation was made without an anchor (offline mock runs
| before an anchor existed); `ai:integrity initialise` numbers those rows when it creates the anchor.
|
| Additive only: nothing existing is altered or dropped, existing rows keep their data (anchor_seq NULL), and re-applying is
| a no-op. The table keeps its InnoDB engine (this migration never changes it).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_budget_reservations') || Schema::hasColumn('ai_budget_reservations', 'anchor_seq')) {
            return;
        }

        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('anchor_seq')->nullable();
            // Unique: one sequence number identifies exactly one reservation (NULLs do not collide).
            $table->unique('anchor_seq', 'ai_budget_reservations_anchor_seq_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_budget_reservations') || ! Schema::hasColumn('ai_budget_reservations', 'anchor_seq')) {
            return;
        }

        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->dropUnique('ai_budget_reservations_anchor_seq_unique');
        });

        Schema::table('ai_budget_reservations', function (Blueprint $table) {
            $table->dropColumn('anchor_seq');
        });
    }
};
