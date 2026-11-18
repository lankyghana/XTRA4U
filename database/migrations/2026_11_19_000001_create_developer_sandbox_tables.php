<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 4 — API sandbox. Additive only.
 *
 *  - developer_sandbox_orders / _payments / _order_events: dedicated sandbox storage. Nothing here
 *    references (or is referenced by) orders, transactions, wallets or any legacy table. vendor_id /
 *    application_id are plain indexed columns on purpose: vendors may not be InnoDB in production, and
 *    developer_applications is rebuilt by earlier migrations (a FK to it would be a cascade hazard).
 *    Foreign keys exist ONLY between the sandbox tables themselves (all created here, all InnoDB).
 *
 *  - developer_webhooks.environment: a webhook endpoint belongs to exactly one environment.
 *    Every existing row becomes 'production' (the column default), preserving current behaviour.
 *
 *  - developer_events: the duplicate-event key (order_id, type) becomes (environment, order_id, type).
 *    Sandbox order ids and production order ids are unrelated number spaces, so without the environment
 *    in the key a sandbox event could collide with (and suppress) a production event. The column
 *    already exists (default 'production'); no existing column is altered, so no table rebuild is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_sandbox_orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('order_ref', 40)->unique();            // sbx_ord_<ulid>, the only id the API shows
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('quote_reference', 40)->unique();      // one order per quote, ever
            $table->string('client_reference', 100)->nullable();  // developer's own reference
            $table->string('idempotency_key', 100);
            $table->string('offer_id', 40);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('reseller_product_id')->nullable();
            $table->json('product_snapshot');                     // immutable: what was quoted/ordered
            $table->string('recipient_phone', 20);
            $table->string('payer_phone', 20)->nullable();
            $table->string('payer_network', 20)->nullable();
            $table->string('network', 40)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('status', 20)->default('awaiting_payment');
            $table->string('payment_status', 12)->default('unpaid');
            $table->string('fulfillment_status', 16)->default('not_started');
            $table->string('payment_reference', 40)->nullable()->unique(); // sbx_pay_<ulid>
            $table->json('fulfillment_result')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'idempotency_key']);
            $table->index(['application_id', 'created_at']);
            $table->index(['application_id', 'client_reference']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('developer_sandbox_payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('payment_ref', 40)->unique();          // sbx_pay_<ulid>
            $table->foreignId('sandbox_order_id')->unique()->constrained('developer_sandbox_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('application_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('method', 40)->default('simulated_mobile_money');
            $table->string('gateway', 40)->default('xtra4u_sandbox');
            $table->string('status', 12)->default('pending');      // pending | paid | failed
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('outcome', 24)->nullable();             // simulation outcome applied: succeeded | failed
            $table->timestamp('simulated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('developer_sandbox_order_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('event_ref', 40)->unique();             // sbe_<ulid>
            $table->foreignId('sandbox_order_id')->constrained('developer_sandbox_orders')->cascadeOnDelete();
            $table->string('type', 40);                            // same vocabulary as webhook event types
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sandbox_order_id', 'id']);
        });

        Schema::table('developer_webhooks', function (Blueprint $table) {
            $table->string('environment', 12)->default('production')->after('application_id'); // sandbox|production
            $table->index(['application_id', 'environment']);
        });

        Schema::table('developer_events', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'type']);
            $table->unique(['environment', 'order_id', 'type'], 'developer_events_env_order_type_unique');
        });
    }

    public function down(): void
    {
        // Sandbox events cannot coexist with the old (order_id, type) key: their numbers overlap production's.
        // They are sandbox-only data, so removing them on rollback never touches production.
        $sandboxEventIds = DB::table('developer_events')->where('environment', 'sandbox')->pluck('id');
        foreach ($sandboxEventIds->chunk(500) as $chunk) {
            DB::table('developer_webhook_attempts')->whereIn('delivery_id', DB::table('developer_webhook_deliveries')->whereIn('event_id', $chunk)->select('id'))->delete();
            DB::table('developer_webhook_deliveries')->whereIn('event_id', $chunk)->delete();
            DB::table('developer_events')->whereIn('id', $chunk)->delete();
        }
        DB::table('developer_webhooks')->where('environment', 'sandbox')->delete();

        Schema::table('developer_events', function (Blueprint $table) {
            $table->dropUnique('developer_events_env_order_type_unique');
            $table->unique(['order_id', 'type']);
        });

        Schema::table('developer_webhooks', function (Blueprint $table) {
            $table->dropIndex(['application_id', 'environment']);
            $table->dropColumn('environment');
        });

        Schema::dropIfExists('developer_sandbox_order_events');
        Schema::dropIfExists('developer_sandbox_payments');
        Schema::dropIfExists('developer_sandbox_orders');
    }
};
