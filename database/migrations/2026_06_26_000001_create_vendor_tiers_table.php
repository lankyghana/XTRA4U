<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the four default tiers
        DB::table('vendor_tiers')->insert([
            [
                'name' => 'Regular',
                'slug' => 'regular',
                'description' => 'Default tier for all approved vendors.',
                'priority' => 0,
                'discount_type' => 'percentage',
                'discount_value' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Silver',
                'slug' => 'silver',
                'description' => 'Tier for vendors with consistent performance.',
                'priority' => 1,
                'discount_type' => 'percentage',
                'discount_value' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gold',
                'slug' => 'gold',
                'description' => 'Tier for high-performing vendors.',
                'priority' => 2,
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Super Vendor',
                'slug' => 'super-vendor',
                'description' => 'Elite tier for top-performing vendors.',
                'priority' => 3,
                'discount_type' => 'percentage',
                'discount_value' => 15,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_tiers');
    }
};
