<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ussd_subscription_events')) {
            return;
        }

        Schema::create('ussd_subscription_events', function (Blueprint $table) {
            $table->id();

            // Nullable so security events with no resolvable subscription
            // (invalid USSD request, failed payment) are still recorded.
            $table->foreignId('ussd_subscription_id')->nullable()
                ->constrained('ussd_subscriptions')->nullOnDelete();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('ussd_plan_id')->nullable();

            $table->string('event', 50);
            $table->text('description')->nullable();
            $table->json('context')->nullable();

            // admin | vendor | system | gateway
            $table->string('actor_type', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_subscription_events');
    }
};
