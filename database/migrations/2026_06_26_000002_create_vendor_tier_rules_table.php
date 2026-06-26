<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_tier_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->constrained('vendor_tiers')->cascadeOnDelete();
            $table->string('rule_key');
            $table->string('operator')->default('>=');
            $table->decimal('value', 15, 2);
            $table->timestamps();

            $table->unique(['tier_id', 'rule_key']);
        });

        // Seed default Silver rules
        $silverId = DB::table('vendor_tiers')->where('slug', 'silver')->value('id');
        $goldId = DB::table('vendor_tiers')->where('slug', 'gold')->value('id');
        $superVendorId = DB::table('vendor_tiers')->where('slug', 'super-vendor')->value('id');

        if ($silverId) {
            DB::table('vendor_tier_rules')->insert([
                ['tier_id' => $silverId, 'rule_key' => 'min_affiliate_orders', 'operator' => '>=', 'value' => 100, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $silverId, 'rule_key' => 'min_success_rate', 'operator' => '>=', 'value' => 90, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $silverId, 'rule_key' => 'min_account_age_days', 'operator' => '>=', 'value' => 30, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if ($goldId) {
            DB::table('vendor_tier_rules')->insert([
                ['tier_id' => $goldId, 'rule_key' => 'min_affiliate_orders', 'operator' => '>=', 'value' => 500, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $goldId, 'rule_key' => 'min_success_rate', 'operator' => '>=', 'value' => 93, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $goldId, 'rule_key' => 'min_account_age_days', 'operator' => '>=', 'value' => 90, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $goldId, 'rule_key' => 'min_affiliate_sales', 'operator' => '>=', 'value' => 10000, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if ($superVendorId) {
            DB::table('vendor_tier_rules')->insert([
                ['tier_id' => $superVendorId, 'rule_key' => 'min_affiliate_orders', 'operator' => '>=', 'value' => 2000, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $superVendorId, 'rule_key' => 'min_success_rate', 'operator' => '>=', 'value' => 95, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $superVendorId, 'rule_key' => 'min_account_age_days', 'operator' => '>=', 'value' => 180, 'created_at' => now(), 'updated_at' => now()],
                ['tier_id' => $superVendorId, 'rule_key' => 'min_affiliate_sales', 'operator' => '>=', 'value' => 50000, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_tier_rules');
    }
};
