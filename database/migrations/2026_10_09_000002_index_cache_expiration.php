<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database cache store deletes an expired row only when that same key is
 * read again, so rows with one-off keys (Utility Bill lookup tokens, lookup
 * results, per-order dispatch markers) are never removed. The scheduled
 * xtra4u:prune-expired-cache deletes them by expiration; this index keeps that
 * delete from scanning the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cache', 'cache_locks'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $table.'_expiration_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->index('expiration'));
            }
        }
    }

    public function down(): void
    {
        foreach (['cache', 'cache_locks'] as $table) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $table.'_expiration_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['expiration']));
            }
        }
    }
};
