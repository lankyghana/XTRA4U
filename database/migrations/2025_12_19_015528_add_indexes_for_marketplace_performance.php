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
        Schema::table('products', function (Blueprint $table) {
            // Add composite index for marketplace queries
            $table->index(['vendor_id', 'is_active', 'is_resellable'], 'idx_products_marketplace');
            
            // Add individual indexes if they don't exist
            if (!Schema::hasColumn('products', 'created_at')) {
                $table->index('created_at');
            }
        });

        Schema::table('reseller_products', function (Blueprint $table) {
            // Add composite index for checking if vendor is already reselling a product
            $table->index(['product_id', 'reseller_vendor_id'], 'idx_reseller_lookup');
        });

        Schema::table('vendors', function (Blueprint $table) {
            // Add index for affiliate parent lookups
            if (!Schema::hasIndex('vendors', 'vendors_affiliate_vendor_id_index')) {
                $table->index('affiliate_vendor_id');
            }
        });

        Schema::table('network_services', function (Blueprint $table) {
            // Add index for active network services with images
            $table->index(['is_active', 'image_path'], 'idx_network_services_active_with_image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_marketplace');
        });

        Schema::table('reseller_products', function (Blueprint $table) {
            $table->dropIndex('idx_reseller_lookup');
        });

        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasIndex('vendors', 'vendors_affiliate_vendor_id_index')) {
                $table->dropIndex('vendors_affiliate_vendor_id_index');
            }
        });

        Schema::table('network_services', function (Blueprint $table) {
            $table->dropIndex('idx_network_services_active_with_image');
        });
    }
};
