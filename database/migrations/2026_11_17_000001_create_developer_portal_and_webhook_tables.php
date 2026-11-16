<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 3. Additive only: new tables plus nullable/defaulted columns on
 * developer_applications (a table created in Milestone 2). Nothing existing is
 * altered destructively and no existing platform table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('developer_applications', function (Blueprint $table) {
            // Production access is a REVIEWED request, separate from `status` (which gates ALL use,
            // including sandbox). none -> requested -> approved|rejected.
            $table->string('production_status', 12)->default('none')->after('environment');
            $table->timestamp('production_requested_at')->nullable();
            $table->timestamp('production_reviewed_at')->nullable();
            $table->string('production_reviewed_by', 40)->nullable(); // e.g. "admin:3" — an actor label, not a FK
            $table->string('production_review_note', 500)->nullable();
            // Vendor-bound apps: the vendor's consent decision.
            $table->timestamp('vendor_link_requested_at')->nullable();
            $table->timestamp('vendor_link_reviewed_at')->nullable();
            $table->index('production_status');
        });

        Schema::create('developer_webhooks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('application_id')->constrained('developer_applications')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('description', 191)->nullable();
            $table->json('events');
            $table->text('secret'); // encrypted at rest (model cast); never logged or re-displayed
            $table->boolean('is_active')->default(true);
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['application_id', 'is_active']);
        });

        // Durable, persisted-before-delivery event log. One row per (order, event type): that
        // unique key is the duplicate-event protection no matter how often detection runs.
        Schema::create('developer_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('event_id', 40)->unique();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('order_id'); // no FK: orders may not be InnoDB in production
            $table->string('type', 40);
            $table->string('environment', 12)->default('production');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('fanned_out_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['order_id', 'type']);
            $table->index(['fanned_out_at', 'id']);
            $table->index(['application_id', 'id']);
        });

        Schema::create('developer_webhook_deliveries', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('delivery_uid', 40)->unique();
            $table->foreignId('webhook_id')->constrained('developer_webhooks')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('developer_events')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending|delivering|succeeded|failed|cancelled
            $table->unsignedInteger('attempts')->default(0);        // attempts in the current retry round
            $table->unsignedInteger('total_attempts')->default(0);  // lifetime, numbers the attempt rows
            $table->unsignedInteger('manual_retries')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_id', 'event_id']); // one delivery per endpoint per event, ever
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('developer_webhook_attempts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('delivery_id')->constrained('developer_webhook_deliveries')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('outcome', 24); // success|http_error|redirect_blocked|blocked|timeout|connection_failed|tls_error|too_large|error
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 120)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['delivery_id', 'attempt_no']);
        });

        // Sweeper checkpoint: the last state observed per API order.
        Schema::create('developer_order_states', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('application_id');
            $table->string('payment_state', 12);
            $table->string('fulfillment_state', 16);
            $table->boolean('is_terminal')->default(false); // fulfilment completed: nothing further to detect
            $table->timestamp('last_seen_at');
            $table->index('is_terminal');
        });

        Schema::create('developer_audit_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('actor', 40); // developer:3 | admin:1 | vendor:7 | system
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->string('action', 60);
            $table->json('meta')->nullable(); // never secrets
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['developer_audit_events', 'developer_order_states', 'developer_webhook_attempts', 'developer_webhook_deliveries', 'developer_events', 'developer_webhooks'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::table('developer_applications', function (Blueprint $table) {
            $table->dropIndex(['production_status']);
            $table->dropColumn([
                'production_status', 'production_requested_at', 'production_reviewed_at',
                'production_reviewed_by', 'production_review_note',
                'vendor_link_requested_at', 'vendor_link_reviewed_at',
            ]);
        });
    }
};
