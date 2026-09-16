<?php

use App\Console\Commands\AuditPaymentUniqueness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level single-use guarantees: one real-world payment may settle at
 * most one order.
 *
 *   - orders.payment_reference must be unique. It is the only payable table
 *     where it was not: afa_registrations, result_checker_orders and
 *     ussd_subscriptions have all declared `unique()` on theirs from the start.
 *   - (payment_gateway, gateway_transaction_id) must be unique, so a gateway
 *     transaction cannot be claimed by two orders.
 *
 * WHY THIS IS ITS OWN MIGRATION, AND WHY IT THROWS
 *
 * An earlier version of this work added these constraints inside the main
 * schema migration and SKIPPED them (with a log line) when legacy duplicates
 * made them impossible. That was wrong: it meant `migrations` could record the
 * same migration as completed on two databases where one has the constraint
 * and the other silently does not — schema drift that later code would be
 * entitled to assume away. Tests would pass against a clean test database
 * while production quietly lacked the guarantee.
 *
 * So this migration is all-or-nothing. If the data is not clean it ABORTS the
 * deploy with the offending rows named, and stays unapplied, so
 * "migration completed" always means "the constraint exists".
 *
 * Operationally this is safe to gate a deploy on, because it is not what
 * protects you: PaymentIntegrityGuard's application-level single-use check
 * (added in the same change) is already in force from the moment the code
 * deploys. This constraint is the belt to that braces. If the deploy aborts
 * here, run `php artisan payments:audit-uniqueness --details`, resolve the
 * historical duplicates, and migrate again.
 *
 * NULLs are exempt from uniqueness in both MySQL and SQLite, so the many
 * orders with no reference or transaction id are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var AuditPaymentUniqueness $auditor */
        $auditor = app(AuditPaymentUniqueness::class);

        $blockers = $auditor->duplicateGroups();

        if ($blockers->isNotEmpty()) {
            throw new RuntimeException(
                "Cannot add payment single-use constraints: orders contains duplicate values.\n\n"
                .$auditor->describe($blockers)
                ."\nThese are historical rows that must be resolved before the constraint can exist.\n"
                ."Run `php artisan payments:audit-uniqueness --details` for the full list.\n"
                ."The application-level single-use check in PaymentIntegrityGuard is already active,\n"
                ."so deploying the rest of this hardening without this constraint is safe in the interim.\n"
            );
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['payment_reference'], 'orders_payment_reference_unique');
            $table->unique(['payment_gateway', 'gateway_transaction_id'], 'orders_gateway_transaction_unique');
        });
    }

    public function down(): void
    {
        foreach (['orders_payment_reference_unique', 'orders_gateway_transaction_unique'] as $index) {
            Schema::table('orders', function (Blueprint $table) use ($index) {
                $table->dropUnique($index);
            });
        }
    }
};
