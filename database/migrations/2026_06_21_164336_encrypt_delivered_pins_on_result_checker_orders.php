<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Change the column from JSON to TEXT.
        // A json column enforces strict json parsing.
        // encrypted:array stores a base64 encoded string, not valid JSON.
        Schema::table('result_checker_orders', function (Blueprint $table) {
            $table->text('delivered_pins_json')->nullable()->change();
        });

        // 2. Encrypt all existing unencrypted data
        DB::table('result_checker_orders')
            ->whereNotNull('delivered_pins_json')
            ->orderBy('id')
            ->chunk(100, function ($orders) {
                foreach ($orders as $order) {
                    $raw = $order->delivered_pins_json;
                    
                    // Check if it's not already encrypted. Laravel encrypted strings start with eyJpdiI ({"iv": in base64)
                    if ($raw && !str_starts_with($raw, 'eyJpdiI')) {
                        // Since it was a json column, $raw is the raw JSON string.
                        // Laravel's encrypted:array cast expects an encrypted JSON string,
                        // so we can directly encrypt the raw JSON string.
                        $encrypted = Crypt::encryptString($raw);
                        
                        DB::table('result_checker_orders')
                            ->where('id', $order->id)
                            ->update(['delivered_pins_json' => $encrypted]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Decrypt all existing encrypted data back to plain JSON
        DB::table('result_checker_orders')
            ->whereNotNull('delivered_pins_json')
            ->orderBy('id')
            ->chunk(100, function ($orders) {
                foreach ($orders as $order) {
                    $raw = $order->delivered_pins_json;
                    
                    if ($raw && str_starts_with($raw, 'eyJpdiI')) {
                        try {
                            $decrypted = Crypt::decryptString($raw);
                            DB::table('result_checker_orders')
                                ->where('id', $order->id)
                                ->update(['delivered_pins_json' => $decrypted]);
                        } catch (\Exception $e) {
                            // ignore if decryption fails during rollback
                        }
                    }
                }
            });

        // 2. Revert column back to JSON
        Schema::table('result_checker_orders', function (Blueprint $table) {
            $table->json('delivered_pins_json')->nullable()->change();
        });
    }
};
