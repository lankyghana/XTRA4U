<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateGatewayConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateways:migrate-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate payment gateway configurations from .env to database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Migrating payment gateway configurations from .env to database...');

        try {
            DB::beginTransaction();

            // Migrate Paystack configuration
            $this->migratePaystack();
            
            // Migrate BulkClix configurations
            $this->migrateBulkClix();

            DB::commit();

            $this->info('✅ Payment gateway configurations migrated successfully!');
            $this->newLine();
            $this->info('Next steps:');
            $this->info('1. Visit /admin/payment-gateways to manage your gateways');
            $this->info('2. Test each gateway configuration');
            $this->info('3. Set your preferred default gateways');
            $this->info('4. Consider removing sensitive keys from .env for security');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Failed to migrate configurations: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function migratePaystack()
    {
        $publicKey = config('services.paystack.public_key');
        $secretKey = config('services.paystack.secret_key');
        $paymentUrl = config('services.paystack.payment_url', 'https://api.paystack.co');

        if ($publicKey || $secretKey) {
            $existing = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_PAYSTACK)
                ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
                ->first();

            if ($existing) {
                // Update existing configuration
                $configData = $existing->config_data;
                if ($publicKey) $configData['public_key'] = $publicKey;
                if ($secretKey) $configData['secret_key'] = $secretKey;
                if ($paymentUrl) $configData['payment_url'] = $paymentUrl;

                $existing->update(['config_data' => $configData]);
                $this->info('Updated existing Paystack configuration');
            } else {
                // Create new configuration
                PaymentGatewayConfig::create([
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
                    'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
                    'is_active' => true,
                    'is_default' => true,
                    'environment' => $this->detectEnvironment($publicKey ?: $secretKey),
                    'config_data' => [
                        'public_key' => $publicKey ?: '',
                        'secret_key' => $secretKey ?: '',
                        'payment_url' => $paymentUrl,
                    ],
                    'supported_features' => [
                        'cards' => true,
                        'bank_transfer' => true,
                        'mobile_money' => true,
                        'ussd' => true,
                    ],
                ]);
                $this->info('Created new Paystack configuration');
            }
        }
    }

    protected function migrateBulkClix()
    {
        $apiKey = config('services.bulkclix.api_key');
        $senderId = config('services.bulkclix.sender_id', 'XTRA4U');
        $baseUrl = config('services.bulkclix.base_url', 'https://api.bulkclix.com/api/v1');

        if ($apiKey) {
            $environment = $this->detectEnvironment($apiKey);

            // Migrate BulkClix for Payouts
            $existingPayout = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_BULKCLIX)
                ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
                ->first();

            if ($existingPayout) {
                $configData = $existingPayout->config_data;
                $configData['api_key'] = $apiKey;
                $configData['base_url'] = $baseUrl;
                $configData['sender_id'] = $senderId;
                $existingPayout->update(['config_data' => $configData]);
                $this->info('Updated existing BulkClix payout configuration');
            } else {
                PaymentGatewayConfig::create([
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
                    'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
                    'is_active' => true,
                    'is_default' => true,
                    'environment' => $environment,
                    'config_data' => [
                        'api_key' => $apiKey,
                        'base_url' => $baseUrl,
                        'sender_id' => $senderId,
                    ],
                    'supported_features' => [
                        'mtn_momo' => true,
                        'telecel_momo' => true,
                        'airteltigo_momo' => true,
                    ],
                ]);
                $this->info('Created new BulkClix payout configuration');
            }

            // Migrate BulkClix for SMS
            $existingSms = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_BULKCLIX)
                ->where('gateway_type', PaymentGatewayConfig::TYPE_SMS)
                ->first();

            if ($existingSms) {
                $configData = $existingSms->config_data;
                $configData['api_key'] = $apiKey;
                $configData['base_url'] = $baseUrl;
                $configData['sender_id'] = $senderId;
                $existingSms->update(['config_data' => $configData]);
                $this->info('Updated existing BulkClix SMS configuration');
            } else {
                PaymentGatewayConfig::create([
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
                    'gateway_type' => PaymentGatewayConfig::TYPE_SMS,
                    'is_active' => true,
                    'is_default' => true,
                    'environment' => $environment,
                    'config_data' => [
                        'api_key' => $apiKey,
                        'base_url' => $baseUrl,
                        'sender_id' => $senderId,
                    ],
                    'supported_features' => [
                        'sms' => true,
                        'bulk_sms' => true,
                    ],
                ]);
                $this->info('Created new BulkClix SMS configuration');
            }
        }
    }

    protected function detectEnvironment(string $key): string
    {
        // Detect environment based on key patterns
        if (str_contains($key, '_live_') || str_contains($key, 'pk_live_') || str_contains($key, 'sk_live_')) {
            return PaymentGatewayConfig::ENV_LIVE;
        }
        
        return PaymentGatewayConfig::ENV_SANDBOX;
    }
}