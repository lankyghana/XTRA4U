<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ussd_plans')) {
            Schema::create('ussd_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2);
                $table->unsignedInteger('included_sessions');
                $table->unsignedSmallInteger('duration_days');

                // Dialled between the global base code and the vendor id:
                // <base_code><extension_code>*<vendor_id>#  ->  *203*45*102#
                // Unique across soft-deleted rows too: a retired plan keeps its
                // extension reserved so historical codes are never re-issued.
                $table->string('extension_code', 10)->unique();

                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'display_order']);
            });
        }

        // Seed the three reference plans. insertOrIgnore keys off the unique
        // extension_code, so re-running this migration is a no-op.
        $now = now();
        $plans = [
            [
                'name' => 'Starter',
                'description' => 'Entry-level USSD access for new vendors.',
                'price' => 80.00,
                'included_sessions' => 5000,
                'duration_days' => 30,
                'extension_code' => '45',
                'display_order' => 1,
            ],
            [
                'name' => 'Business',
                'description' => 'For established vendors with steady USSD traffic.',
                'price' => 250.00,
                'included_sessions' => 20000,
                'duration_days' => 30,
                'extension_code' => '46',
                'display_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'description' => 'High-volume USSD access with the largest session allowance.',
                'price' => 800.00,
                'included_sessions' => 100000,
                'duration_days' => 30,
                'extension_code' => '47',
                'display_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('ussd_plans')->insertOrIgnore($plan + [
                'is_active' => true,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_plans');
    }
};
