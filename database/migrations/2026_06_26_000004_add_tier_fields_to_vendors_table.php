<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('tier_id')->nullable()->constrained('vendor_tiers')->nullOnDelete()->after('is_afa_affiliate');
            $table->foreignId('eligible_for_tier_id')->nullable()->constrained('vendor_tiers')->nullOnDelete()->after('tier_id');
            $table->boolean('is_tier_eligible')->default(false)->after('eligible_for_tier_id');
            $table->timestamp('tier_qualified_at')->nullable()->after('is_tier_eligible');
            $table->timestamp('tier_reviewed_at')->nullable()->after('tier_qualified_at');
            $table->foreignId('tier_reviewed_by')->nullable()->constrained('admins')->nullOnDelete()->after('tier_reviewed_at');
        });

        // Assign the Regular tier to all existing approved vendors
        $regularTierId = DB::table('vendor_tiers')->where('slug', 'regular')->value('id');
        if ($regularTierId) {
            DB::table('vendors')
                ->where('is_approved', true)
                ->whereNull('tier_id')
                ->update(['tier_id' => $regularTierId]);
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['tier_id']);
            $table->dropForeign(['eligible_for_tier_id']);
            $table->dropForeign(['tier_reviewed_by']);
            $table->dropColumn(['tier_id', 'eligible_for_tier_id', 'is_tier_eligible', 'tier_qualified_at', 'tier_reviewed_at', 'tier_reviewed_by']);
        });
    }
};
