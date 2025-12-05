<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('reseller_product_id')->nullable()->after('vendor_id')->constrained('reseller_products')->onDelete('set null');
            $table->foreignId('owner_vendor_id')->nullable()->after('reseller_product_id')->constrained('vendors')->onDelete('set null');
            $table->foreignId('reseller_vendor_id')->nullable()->after('owner_vendor_id')->constrained('vendors')->onDelete('set null');
            $table->decimal('base_price', 10, 2)->nullable()->after('amount_paid');
            $table->decimal('markup_price', 10, 2)->nullable()->after('base_price');
            $table->decimal('owner_earning', 10, 2)->nullable()->after('markup_price');
            $table->decimal('reseller_earning', 10, 2)->nullable()->after('owner_earning');
            $table->decimal('platform_commission', 10, 2)->nullable()->after('reseller_earning');
            $table->boolean('is_reseller_order')->default(false)->after('platform_commission');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_product_id']);
            $table->dropForeign(['owner_vendor_id']);
            $table->dropForeign(['reseller_vendor_id']);
            $table->dropColumn([
                'reseller_product_id',
                'owner_vendor_id',
                'reseller_vendor_id',
                'base_price',
                'markup_price',
                'owner_earning',
                'reseller_earning',
                'platform_commission',
                'is_reseller_order',
            ]);
        });
    }
};
