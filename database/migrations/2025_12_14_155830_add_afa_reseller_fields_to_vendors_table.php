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
        Schema::table('vendors', function (Blueprint $table) {
            // Reseller AFA settings
            $table->boolean('afa_reseller_enabled')->default(false)->after('afa_enabled');
            $table->unsignedBigInteger('afa_source_vendor_id')->nullable()->after('afa_reseller_enabled');
            $table->decimal('afa_base_price', 10, 2)->default(0)->after('afa_source_vendor_id');
            $table->decimal('afa_markup', 10, 2)->default(0)->after('afa_base_price');
            $table->decimal('afa_selling_price', 10, 2)->default(0)->after('afa_markup');
            
            // Foreign key to source vendor
            $table->foreign('afa_source_vendor_id')
                  ->references('id')
                  ->on('vendors')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['afa_source_vendor_id']);
            $table->dropColumn([
                'afa_reseller_enabled',
                'afa_source_vendor_id',
                'afa_base_price',
                'afa_markup',
                'afa_selling_price',
            ]);
        });
    }
};
