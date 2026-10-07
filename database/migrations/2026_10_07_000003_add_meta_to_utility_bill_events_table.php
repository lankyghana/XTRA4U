<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured detail for append-only utility bill events. Used to keep a
 * queryable per-attempt record when a new provider attempt is started: the
 * closed attempt's request/provider references, provider status, timestamps,
 * the admin who started the next attempt and why. Nullable, additive, no
 * existing row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_bill_events', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('utility_bill_events', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
