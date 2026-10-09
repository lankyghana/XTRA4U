<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Utility Bills (KiNG FLEXY) — global platform service.
 *
 * - utility_biller_configs: admin-owned per-biller switch + vendor commission terms.
 * - utility_bill_config_audits: who changed which commission/availability setting, old -> new.
 * - utility_bill_orders: one row per sale; 1:1 with the `orders` row that carries the payment.
 * - utility_bill_events: append-only provider/fulfillment timeline (no secrets, no raw payloads).
 *
 * Money is decimal(10,2) like the rest of the schema; percentages are decimal(10,4).
 */
return new class extends Migration
{
    /** Created by this migration, children first. */
    private const TABLES = ['utility_bill_events', 'utility_bill_orders', 'utility_bill_config_audits', 'utility_biller_configs'];

    public function up(): void
    {
        $this->assertReferencedTablesSupportForeignKeys();
        $this->dropEmptyLeftoversOfAFailedRun();

        Schema::create('utility_biller_configs', function (Blueprint $table) {
            $table->id();
            $table->string('biller_key', 40)->unique();
            // Disabled until an admin explicitly enables it.
            $table->boolean('is_enabled')->default(false);
            // 'percentage' | 'fixed'. Value 0 = no vendor commission. No default rate is invented.
            $table->string('commission_type', 12)->default('percentage');
            $table->decimal('commission_value', 10, 4)->default(0);
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();
        });

        Schema::create('utility_bill_config_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('scope', 12); // 'global' | 'biller'
            $table->string('biller_key', 40)->nullable();
            $table->json('old_values');
            $table->json('new_values');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scope', 'biller_key']);
            $table->index('created_at');
        });

        Schema::create('utility_bill_orders', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 24)->unique();
            // Unguessable capability for the public status/receipt URL.
            $table->string('access_token', 64)->unique();
            $table->unsignedBigInteger('order_id')->unique();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();

            // Frozen attribution (null = direct XTRA4U sale). Never re-derived.
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();

            // The verified bill target, frozen at order creation.
            $table->string('biller_key', 40);
            $table->string('biller_label', 100);
            $table->string('account_number', 40);
            $table->string('account_name', 150)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->json('lookup_snapshot')->nullable();
            $table->decimal('amount_due_at_lookup', 12, 2)->nullable();

            // Frozen customer-facing terms. No platform fee: expected == bill.
            $table->decimal('bill_amount', 10, 2);
            $table->decimal('expected_amount', 10, 2);
            $table->string('currency', 3)->default('GHS');

            // Fulfillment lifecycle (independent of orders.payment_status).
            $table->string('fulfillment_status', 24)->default('awaiting_payment');
            $table->string('provider', 20)->default('kingflexy');
            $table->unsignedTinyInteger('provider_attempt')->default(1);
            // OUR idempotency key sent to the provider; persisted before the call.
            $table->string('provider_request_reference', 64)->nullable()->unique();
            // The PROVIDER's own order reference (UTIL-...), used for status queries.
            $table->string('provider_order_reference', 80)->nullable()->unique();
            $table->string('provider_status', 20)->nullable();
            $table->string('provider_payment_status', 20)->nullable();
            $table->string('provider_status_reason', 255)->nullable();
            // Provider -> XTRA4U economics. Reported only; NEVER a vendor commission.
            $table->decimal('provider_commission_share_percent', 6, 2)->nullable();
            $table->decimal('provider_commission_earned', 10, 2)->nullable();

            $table->string('claim_token', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('next_submit_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('status_check_attempts')->default(0);
            $table->timestamp('last_status_check_at')->nullable();
            $table->timestamp('next_status_check_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->string('last_error_message', 255)->nullable();

            // Frozen vendor commission terms (XTRA4U -> vendor).
            $table->string('commission_type', 12)->nullable();
            $table->decimal('commission_value', 10, 4)->nullable();
            $table->string('commission_basis', 24)->default('bill_face_value');
            $table->decimal('commission_basis_amount', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            // none | pending | credited
            $table->string('commission_status', 12)->default('none');
            $table->timestamp('commission_credited_at')->nullable();
            $table->unsignedBigInteger('commission_wallet_ledger_id')->nullable()->unique();

            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['fulfillment_status', 'next_status_check_at'], 'ubo_status_poll_idx');
            $table->index(['fulfillment_status', 'next_submit_at'], 'ubo_submit_idx');
            $table->index('biller_key');
            $table->index('commission_status');
            $table->index('created_at');
        });

        Schema::create('utility_bill_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('utility_bill_order_id');
            $table->foreign('utility_bill_order_id')->references('id')->on('utility_bill_orders')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('detail', 255)->nullable();
            $table->string('actor', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['utility_bill_order_id', 'id']);
            $table->index(['kind', 'created_at']);
        });
    }

    /**
     * MySQL/MariaDB cannot add a foreign key to a MyISAM table (errno 150), and DDL is not
     * transactional, so a failure half-way would leave tables behind. Check first and stop with
     * an actionable message instead.
     */
    private function assertReferencedTablesSupportForeignKeys(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $engines = DB::select(
            "select TABLE_NAME as name, ENGINE as engine from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_NAME in ('orders', 'vendors')",
            [DB::connection()->getDatabaseName()]
        );

        foreach ($engines as $row) {
            [$table, $engine] = [$row->name, $row->engine];
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                throw new RuntimeException("Table `{$table}` uses the {$engine} engine, which cannot hold foreign keys (and ignores transactions and row locks). Back up, then run `ALTER TABLE {$table} ENGINE=InnoDB;` and re-run the migration.");
            }
        }
    }

    /**
     * A previous run that failed part-way (non-transactional DDL) may have left some of these
     * tables behind. Remove them only when they hold no rows; never touch data.
     */
    private function dropEmptyLeftoversOfAFailedRun(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::select('select 1 from '.DB::getQueryGrammar()->wrapTable($table).' limit 1') !== []) {
                throw new RuntimeException("Table `{$table}` already exists and contains data; refusing to recreate it. Investigate before re-running this migration.");
            }
        }

        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_bill_events');
        Schema::dropIfExists('utility_bill_orders');
        Schema::dropIfExists('utility_bill_config_audits');
        Schema::dropIfExists('utility_biller_configs');
    }
};
