<?php

use App\Models\PaymentGatewayConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table) {
            $table->boolean('supports_collection')->default(false)->after('gateway_type');
            $table->boolean('supports_generic')->default(false)->after('supports_collection');
            $table->boolean('supports_payout')->default(false)->after('supports_generic');
            $table->boolean('supports_sms')->default(false)->after('supports_payout');
        });

        // Backfill capabilities for existing rows based on gateway_name.
        // Note: this is intentionally gateway-level (not type-level) to allow consistent safety checks.
        $capabilities = [
            PaymentGatewayConfig::GATEWAY_PAYSTACK => [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => false,
            ],
            PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => false,
            ],
            PaymentGatewayConfig::GATEWAY_BULKCLIX => [
                'supports_collection' => false,
                'supports_generic' => false,
                'supports_payout' => true,
                'supports_sms' => true,
            ],
            PaymentGatewayConfig::GATEWAY_HUBTEL => [
                'supports_collection' => true,
                'supports_generic' => false, // Safety: generic/AFA is not wired.
                'supports_payout' => true,
                'supports_sms' => true,
            ],
        ];

        foreach ($capabilities as $gatewayName => $flags) {
            DB::table('payment_gateway_configs')
                ->where('gateway_name', $gatewayName)
                ->update($flags);
        }
    }

    public function down(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table) {
            $table->dropColumn([
                'supports_collection',
                'supports_generic',
                'supports_payout',
                'supports_sms',
            ]);
        });
    }
};
