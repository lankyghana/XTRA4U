<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ussd_subscriptions')) {
            return;
        }

        Schema::create('ussd_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('ussd_plan_id')->constrained('ussd_plans')->restrictOnDelete();

            // pending_payment | paid | active | expired | suspended | cancelled
            $table->string('status', 20)->default('pending_payment');

            // Generated on activation: <base_code><extension_code>*<vendor_id>#
            $table->string('ussd_code', 32)->nullable()->unique();

            // Snapshots taken at purchase time. Editing a plan afterwards must
            // never retroactively change what a vendor already paid for.
            $table->string('extension_code', 10);
            $table->decimal('price_paid', 12, 2);
            $table->unsignedInteger('total_sessions');
            $table->unsignedInteger('carried_over_sessions')->default(0);

            $table->unsignedInteger('used_sessions')->default(0);

            $table->string('payment_reference')->nullable()->unique();
            $table->string('payment_gateway')->nullable();
            $table->json('gateway_response')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Set when this subscription is replaced by a renewal/upgrade.
            $table->unsignedBigInteger('superseded_by_id')->nullable();

            // Usage-alert thresholds already notified, e.g. [80, 90, 95, 100].
            $table->json('notified_thresholds')->nullable();

            // Holds the vendor's single subscription slot while this row is
            // pending_payment-cleared (paid/active/suspended); NULL otherwise.
            // MySQL ignores NULLs in unique indexes, which gives us a genuine
            // partial-unique constraint: at most one live subscription per vendor.
            $table->unsignedBigInteger('current_for_vendor_id')->nullable()->unique();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('expires_at');
            $table->index('status');
        });

        // Guard against usage ever exceeding the purchased allowance. Mirrors the
        // wallet_topups consumed<=amount constraint; engines that ignore CHECK
        // simply skip it, and the service layer enforces it regardless.
        try {
            DB::statement('ALTER TABLE ussd_subscriptions ADD CONSTRAINT chk_ussd_subscriptions_used_sessions CHECK (used_sessions <= total_sessions)');
        } catch (\Throwable $e) {
            // Not supported on this engine; application-level guard still applies.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_subscriptions');
    }
};
