<?php

use App\Support\PaymentIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment/pricing integrity hardening — schema.
 *
 * Adds two independent things to `orders`:
 *
 * 1. THE IMMUTABLE FINANCIAL SNAPSHOT (`expected_amount`, `currency`,
 *    `pricing_snapshot_at`). `base_price`/`markup_price` already exist on this
 *    table and are reused rather than duplicated — the change is WHEN they are
 *    written: from now on at order CREATION (frozen terms), not only at
 *    completion (where they were being re-derived from whatever the live
 *    product/reseller rows happened to say at that moment).
 *
 *    `expected_amount` is the one canonical answer to "how much was this exact
 *    order supposed to pay when it was created?". It is never recalculated, so
 *    a later product price change or reseller markup change cannot alter the
 *    economics of an order that already exists.
 *
 * 2. THE PROOF OF PAYMENT (`payment_integrity_status` and the
 *    `gateway_confirmed_*` / `payment_verified_at` diagnostics). This is a new
 *    axis, deliberately NOT folded into `payment_status`, whose existing
 *    unpaid->paid semantics a great deal of UI and reporting depends on.
 *
 * LEGACY DATA POLICY — nothing financial is touched:
 *   - No existing amount, earning, commission, wallet balance, transaction or
 *     status is modified by this migration.
 *   - `expected_amount` is left NULL for every historical row. Inferring it
 *     from today's product price would manufacture "historical truth" that
 *     does not exist (and is exactly how a mispriced order could be made to
 *     look correct).
 *   - Orders already paid/completed are labelled LEGACY_PAID so they keep
 *     working (including anything legitimately mid-fulfillment at deploy).
 *   - Orders not yet paid are labelled LEGACY_UNVERIFIED: they fail closed,
 *     so reconciliation can never silently revive and fulfil one.
 *
 * Single-use UNIQUE constraints are deliberately NOT added here — they live in
 * 2026_09_14_000003, which refuses to apply against dirty data rather than
 * skipping silently. See that migration for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // --- Immutable financial snapshot -------------------------------
            if (! Schema::hasColumn('orders', 'expected_amount')) {
                $table->decimal('expected_amount', 10, 2)->nullable()->after('amount_paid');
            }

            if (! Schema::hasColumn('orders', 'currency')) {
                $table->string('currency', 3)->nullable()->after('expected_amount');
            }

            if (! Schema::hasColumn('orders', 'pricing_snapshot_at')) {
                $table->timestamp('pricing_snapshot_at')->nullable()->after('currency');
            }

            // --- Proof of payment -------------------------------------------
            if (! Schema::hasColumn('orders', 'payment_integrity_status')) {
                $table->string('payment_integrity_status', 32)->nullable()->after('payment_status');
            }

            if (! Schema::hasColumn('orders', 'payment_integrity_note')) {
                $table->string('payment_integrity_note', 255)->nullable()->after('payment_integrity_status');
            }

            if (! Schema::hasColumn('orders', 'gateway_confirmed_amount')) {
                $table->decimal('gateway_confirmed_amount', 10, 2)->nullable()->after('payment_integrity_note');
            }

            if (! Schema::hasColumn('orders', 'gateway_confirmed_currency')) {
                $table->string('gateway_confirmed_currency', 3)->nullable()->after('gateway_confirmed_amount');
            }

            if (! Schema::hasColumn('orders', 'gateway_transaction_id')) {
                $table->string('gateway_transaction_id', 120)->nullable()->after('gateway_confirmed_currency');
            }

            if (! Schema::hasColumn('orders', 'payment_verified_aspects')) {
                $table->json('payment_verified_aspects')->nullable()->after('gateway_transaction_id');
            }

            if (! Schema::hasColumn('orders', 'payment_verified_at')) {
                $table->timestamp('payment_verified_at')->nullable()->after('payment_verified_aspects');
            }
        });

        // Indexes the new query paths actually use: the fulfillment/settlement
        // gate filters on payment_integrity_status, and the single-use check
        // looks an order up by gateway_transaction_id.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('payment_integrity_status', 'orders_payment_integrity_status_idx');
            $table->index('gateway_transaction_id', 'orders_gateway_transaction_id_idx');
        });

        $this->backfillLegacyIntegrityStatuses();
    }

    /**
     * Label every pre-existing order. Chunked by primary key so a large
     * production table is updated in bounded statements rather than one
     * table-wide write, and expressed as two targeted UPDATEs (no row-by-row
     * model loading). Only the new column is written.
     */
    private function backfillLegacyIntegrityStatuses(): void
    {
        $maxId = (int) DB::table('orders')->max('id');

        if ($maxId === 0) {
            return;
        }

        $chunkSize = 5000;

        for ($from = 0; $from < $maxId; $from += $chunkSize) {
            $to = $from + $chunkSize;

            DB::table('orders')
                ->whereNull('payment_integrity_status')
                ->where('id', '>', $from)
                ->where('id', '<=', $to)
                ->whereIn('payment_status', ['paid', 'completed'])
                ->update(['payment_integrity_status' => PaymentIntegrity::LEGACY_PAID]);

            DB::table('orders')
                ->whereNull('payment_integrity_status')
                ->where('id', '>', $from)
                ->where('id', '<=', $to)
                ->update(['payment_integrity_status' => PaymentIntegrity::LEGACY_UNVERIFIED]);
        }
    }

    public function down(): void
    {
        foreach ([
            'orders_payment_integrity_status_idx',
            'orders_gateway_transaction_id_idx',
        ] as $index) {
            try {
                Schema::table('orders', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            } catch (\Throwable $e) {
                // Index may have been skipped on the way up.
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'expected_amount',
                'currency',
                'pricing_snapshot_at',
                'payment_integrity_status',
                'payment_integrity_note',
                'gateway_confirmed_amount',
                'gateway_confirmed_currency',
                'gateway_transaction_id',
                'payment_verified_aspects',
                'payment_verified_at',
            ]);
        });
    }
};
