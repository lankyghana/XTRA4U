<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            if (!Schema::hasColumn('reseller_products', 'source_reseller_product_id')) {
                $table->unsignedBigInteger('source_reseller_product_id')->nullable()->after('product_id');
                $table->foreign('source_reseller_product_id')
                    ->references('id')
                    ->on('reseller_products')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            if (Schema::hasColumn('reseller_products', 'source_reseller_product_id')) {
                $table->dropForeign(['source_reseller_product_id']);
                $table->dropColumn('source_reseller_product_id');
            }
        });
    }
};
