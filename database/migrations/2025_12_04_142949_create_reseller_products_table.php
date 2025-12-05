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
        Schema::create('reseller_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('reseller_vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->foreignId('owner_vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->decimal('base_price', 10, 2); // Price set by owner vendor
            $table->decimal('markup_price', 10, 2)->default(0); // Markup added by reseller
            $table->decimal('selling_price', 10, 2); // base_price + markup_price
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure reseller can't resell the same product twice
            $table->unique(['product_id', 'reseller_vendor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_products');
    }
};
