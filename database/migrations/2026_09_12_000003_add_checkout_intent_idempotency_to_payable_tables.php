<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 duplicate-charge prevention — customer-intent / payment-attempt
 * idempotency for every initiation endpoint (orders, afa_registrations,
 * result_checker_orders, ussd_subscriptions, wallet_topups).
 *
 * See App\Services\Payments\CheckoutIntentGuard for the full design
 * rationale. In short: per-reference idempotency (Phase 1-3) cannot stop a
 * customer's retry from creating REF-B while REF-A is still ambiguous,
 * because REF-A and REF-B are both "legitimate" individually. What is
 * missing is a way to recognise "this is the SAME unresolved checkout
 * attempt being retried" as opposed to "this is a genuinely new purchase".
 *
 * - idempotency_key: an opaque token identifying one payment ATTEMPT for one
 *   purchase INTENT. Supplied by the client (ideally persisted client-side
 *   across a double-click/duplicate-POST/refresh of the same intent) but
 *   never trusted as globally unique on its own — see idempotency_scope.
 * - idempotency_scope: server-computed owner scope the key is checked
 *   against (e.g. "session:<id>" for guest checkout, "vendor:<id>" for
 *   authenticated vendor flows). Prevents cross-customer/cross-vendor key
 *   guessing or reuse — a key only ever resolves to an existing row when
 *   BOTH the key and the scope match.
 * - The UNIQUE index is the actual concurrency guard: two requests racing
 *   to insert the same (scope, key) pair can never both succeed, so a
 *   double-click / duplicate POST / two-tab submit can create at most one
 *   row for the same intent even without any explicit lock. Rows without a
 *   key (older records, or a client that sent none) are unaffected — SQL
 *   NULLs never collide with each other in a unique index.
 *
 * Explicit short index names are used throughout: Laravel's default
 * convention for the longest table here
 * (`result_checker_orders_idempotency_scope_idempotency_key_unique`) is 65
 * characters, one over MySQL's 64-character identifier limit.
 */
return new class extends Migration
{
    private array $tables = [
        'orders' => 'orders_idem_key_unique',
        'afa_registrations' => 'afa_regs_idem_key_unique',
        'result_checker_orders' => 'rc_orders_idem_key_unique',
        'ussd_subscriptions' => 'ussd_subs_idem_key_unique',
        'wallet_topups' => 'wallet_topups_idem_key_unique',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $indexName) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->string('idempotency_scope', 150)->nullable();
                $blueprint->string('idempotency_key', 100)->nullable();
                $blueprint->unique(['idempotency_scope', 'idempotency_key'], $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $indexName) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropUnique($indexName);
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['idempotency_scope', 'idempotency_key']);
            });
        }
    }
};
