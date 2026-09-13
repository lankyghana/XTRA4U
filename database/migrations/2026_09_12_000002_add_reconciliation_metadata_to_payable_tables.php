<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 payment reconciliation hardening — minimum schema needed for
 * bounded, backoff-scheduled automatic reconciliation across every
 * payment-backed payable (orders, afa_registrations, result_checker_orders,
 * ussd_subscriptions, wallet_topups).
 *
 * Deliberately NOT added: a separate reconciliation_status enum (Phase 2's
 * rule still applies — never invent a new status when the existing
 * payment_status/status columns already say "still pending"), and a full
 * reconciliation_error/response column (would risk accumulating sensitive
 * provider payloads — see PaymentReconciliationService's logging policy,
 * which logs structured, non-secret events instead).
 *
 * - reconciliation_attempts: how many automatic attempts have been made.
 *   Drives the backoff schedule and the maximum-automatic-window cutoff.
 * - last_reconciliation_at: when an attempt (of any outcome) last ran.
 *   Pure observability.
 * - next_reconciliation_at: when this record next becomes eligible for an
 *   automatic attempt. NULLable and INDEXED — this is the column
 *   payments:reconcile's eligibility query filters on, so "due" records can
 *   be selected without a full-table scan. A record that has exhausted the
 *   maximum automatic window (see PaymentReconciliationService) gets this
 *   set far in the future ("parked") rather than cancelled — it remains
 *   selectable via `payments:reconcile --reference=` for manual review, but
 *   is never picked up by the automatic batch again.
 * - reconciliation_note: a short, human-readable reason code (e.g.
 *   "no_gateway", "manual_review: max window exceeded", "integrity_mismatch:
 *   amount") — never a raw provider payload or secret — giving operational
 *   visibility into why a record is stuck without a dashboard.
 */
return new class extends Migration
{
    private array $tables = [
        'orders',
        'afa_registrations',
        'result_checker_orders',
        'ussd_subscriptions',
        'wallet_topups',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedInteger('reconciliation_attempts')->default(0);
                $blueprint->timestamp('last_reconciliation_at')->nullable();
                $blueprint->timestamp('next_reconciliation_at')->nullable()->index();
                $blueprint->string('reconciliation_note', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_next_reconciliation_at_index');
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn([
                    'reconciliation_attempts',
                    'last_reconciliation_at',
                    'next_reconciliation_at',
                    'reconciliation_note',
                ]);
            });
        }
    }
};
