<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateResultCheckerTables extends Migration
{
    public function up(): void
    {
        // 1. Create result_checker_orders table
        if (!Schema::hasTable('result_checker_orders')) {
            Schema::create('result_checker_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('network_services')->onDelete('cascade');
            $table->string('customer_phone')->index();
            $table->string('customer_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->json('delivered_pins_json')->nullable(); // [{pin, serial}, ...]
            $table->enum('status', ['pending_payment', 'pending_stock', 'processing', 'completed', 'failed'])
                ->default('pending_payment')->index();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('payment_gateway')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['service_id', 'status']);
            $table->index(['payment_reference']);
            });
        }

        // 2. Create result_checker_pins table
        if (!Schema::hasTable('result_checker_pins')) {
            Schema::create('result_checker_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('network_services')->onDelete('cascade');
            $table->string('pin', 50)->index();
            $table->string('serial', 50)->index();
            $table->enum('status', ['available', 'sold', 'reserved'])->default('available')->index();
            $table->foreignId('result_checker_order_id')->nullable()->constrained('result_checker_orders')->onDelete('set null');
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'pin', 'serial']);
            $table->index(['status', 'service_id']);
            $table->index(['result_checker_order_id']);
            });
        }

        // 3. Create vendor_result_checker_settings table
        if (!Schema::hasTable('vendor_result_checker_settings')) {
            Schema::create('vendor_result_checker_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('network_services')->onDelete('cascade');
            $table->decimal('profit_amount', 10, 2)->default(0);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->unique(['vendor_id', 'service_id']);
            $table->index(['vendor_id', 'is_active']);
            });
        }

        // 4. Add checker type support to network_services table
        Schema::table('network_services', function (Blueprint $table) {
            if (!Schema::hasColumn('network_services', 'is_checker_type')) {
                $table->boolean('is_checker_type')->default(false)->after('category')->index();
            }
            if (!Schema::hasColumn('network_services', 'base_price')) {
                $table->decimal('base_price', 10, 2)->nullable()->after('is_checker_type');
            }
            if (!Schema::hasColumn('network_services', 'checker_code')) {
                $table->string('checker_code')->nullable()->after('base_price')->unique();
            }
            if (!Schema::hasColumn('network_services', 'checker_description')) {
                $table->text('checker_description')->nullable()->after('checker_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('network_services', function (Blueprint $table) {
            $table->dropIndex(['is_checker_type']);
            $table->dropIndex(['checker_code']);
            $table->dropColumn(['is_checker_type', 'base_price', 'checker_code', 'checker_description']);
        });
        Schema::dropIfExists('vendor_result_checker_settings');
        Schema::dropIfExists('result_checker_pins');
        Schema::dropIfExists('result_checker_orders');
    }
}

