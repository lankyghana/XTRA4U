<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| M5.7.1: durable AI budget ledger and emergency kill switch.
|
| These three tables are the AUTHORITATIVE state of the AI budget and the emergency shutdown. They deliberately live in
| the transactional database, never in the Laravel cache: `php artisan cache:clear` (or any cache eviction, restart or
| deploy) cannot reset a spending counter or re-enable the engine.
|
|  - ai_usage_counters       one row per (day, scope): requests / tokens / cost charged so far. Reservations change it with
|                            conditional atomic UPDATEs inside a transaction, so two processes cannot both take the last
|                            budget. Always equal to the sum of the ledger rows below; a mismatch is treated as corruption.
|  - ai_budget_reservations  the LEDGER: one row per provider attempt, with the amounts it currently charges and its state.
|                            Settling / failing it is a state transition under a row lock, so it can only ever happen once.
|  - ai_runtime_flags        the kill switch (and the per-day budget-violation flags). Seeded ENGAGED: AI stays off until
|                            an operator explicitly runs `php artisan ai:kill-switch release`.
|
| InnoDB is required on MySQL/MariaDB (row locks and transactions): the engine is set explicitly instead of trusting the
| server default (the production `orders` table was once MyISAM). Re-applying is safe: nothing is dropped or overwritten,
| and the seed never overrides an existing flag.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_counters')) {
            Schema::create('ai_usage_counters', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('day', 8);
                $table->string('scope', 40);
                $table->bigInteger('requests')->default(0);
                $table->bigInteger('tokens')->default(0);
                $table->bigInteger('cost_micro_usd')->default(0);
                $table->bigInteger('successes')->default(0);
                $table->bigInteger('failures')->default(0);
                $table->timestamps();

                $table->unique(['day', 'scope'], 'ai_usage_counters_day_scope_unique');
            });
        }

        if (! Schema::hasTable('ai_budget_reservations')) {
            Schema::create('ai_budget_reservations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->string('id', 26)->primary();
                $table->string('day', 8);
                $table->string('surface', 20);
                // open | settled | settled_unknown | failed_unknown | released
                $table->string('state', 20)->default('open');
                $table->string('retry_of', 26)->nullable();
                // What this attempt CURRENTLY charges against the counters.
                $table->bigInteger('requests')->default(1);
                $table->bigInteger('tokens')->default(0);
                $table->bigInteger('cost_micro_usd')->default(0);
                // What was reserved up front (the worst case); kept for audit and to detect over-use.
                $table->bigInteger('reserved_tokens')->default(0);
                $table->bigInteger('reserved_cost_micro_usd')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('closed_at')->nullable();

                $table->index(['day', 'surface'], 'ai_budget_reservations_day_surface_index');
                $table->index(['day', 'state'], 'ai_budget_reservations_day_state_index');
            });
        }

        if (! Schema::hasTable('ai_runtime_flags')) {
            Schema::create('ai_runtime_flags', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->string('name', 64)->primary();
                $table->string('value', 20);
                $table->string('reason', 255)->nullable();
                $table->timestamps();
            });
        }

        // Fail-closed default: AI is OFF until released on purpose. insertOrIgnore never overwrites an existing state.
        DB::table('ai_runtime_flags')->insertOrIgnore([
            'name' => 'kill_switch',
            'value' => 'engaged',
            'reason' => 'initial state: AI stays off until an operator runs ai:kill-switch release',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runtime_flags');
        Schema::dropIfExists('ai_budget_reservations');
        Schema::dropIfExists('ai_usage_counters');
    }
};
