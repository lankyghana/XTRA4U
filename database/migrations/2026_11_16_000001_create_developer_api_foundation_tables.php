<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_plans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('requests_per_minute')->default(60);
            $table->unsignedBigInteger('monthly_request_quota')->nullable(); // null = unlimited
            $table->unsignedInteger('max_applications')->default(3);
            $table->unsignedInteger('max_webhooks')->default(3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('developers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('company')->nullable();
            $table->string('password');
            $table->string('status', 20)->default('active')->index(); // active|suspended
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('developer_applications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('developer_id')->constrained('developers')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('developer_plans')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Authoritative environment. Keys copy it at creation and must match it on every request.
            $table->string('environment', 12)->default('sandbox'); // sandbox|production
            $table->string('status', 24)->default('active')->index(); // active|pending_approval|suspended|revoked
            // Vendor-specific integration: plain indexed column (vendors/orders may be non-InnoDB in prod).
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('vendor_link_status', 16)->default('none'); // none|pending|approved
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('production_approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('developer_api_keys', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('application_id')->constrained('developer_applications')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('prefix', 12)->unique();
            $table->char('key_hash', 64)->unique(); // sha256 of the full key; the key itself is never stored
            $table->string('environment', 12); // sandbox|production
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('rotated_from_id')->nullable();
            $table->timestamps();
        });

        Schema::create('developer_api_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('request_id', 40)->index();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('environment', 12)->nullable();
            $table->string('method', 8);
            $table->string('path', 191); // route pattern, never the raw URL or query string
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->string('error_code', 50)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['application_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('api_quotes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('application_id')->index();
            $table->string('environment', 12)->default('production');
            $table->string('offer_id', 40);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('reseller_product_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('recipient_phone', 20);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->json('snapshot');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['api_quotes', 'developer_api_requests', 'developer_api_keys', 'developer_applications', 'developers', 'developer_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
