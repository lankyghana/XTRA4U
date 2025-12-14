<?php

namespace Database\Seeders;

use App\Models\PaymentGatewayConfig;
use Illuminate\Database\Seeder;

class PaymentGatewayConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Paystack configuration
        $paystack = new PaymentGatewayConfig();
        $paystack->gateway_name = PaymentGatewayConfig::GATEWAY_PAYSTACK;
        $paystack->gateway_type = PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION;
        $paystack->is_active = true;
        $paystack->is_default = true;
        $paystack->environment = env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX;
        $paystack->config_data = [
            'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
            'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
            'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
        ];
        $paystack->supported_features = [
            'cards' => true,
            'bank_transfer' => true,
            'mobile_money' => true,
            'ussd' => true,
        ];
        $paystack->save();

        // Create Flutterwave configuration (inactive by default)
        $flutterwave = new PaymentGatewayConfig();
        $flutterwave->gateway_name = PaymentGatewayConfig::GATEWAY_FLUTTERWAVE;
        $flutterwave->gateway_type = PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION;
        $flutterwave->is_active = false;
        $flutterwave->is_default = false;
        $flutterwave->environment = PaymentGatewayConfig::ENV_SANDBOX;
        $flutterwave->config_data = [
            'public_key' => '',
            'secret_key' => '',
            'encryption_key' => '',
            'payment_url' => 'https://api.flutterwave.com/v3',
        ];
        $flutterwave->supported_features = [
            'cards' => true,
            'bank_transfer' => true,
            'mobile_money' => true,
            'ussd' => true,
            'apple_pay' => true,
            'google_pay' => true,
        ];
        $flutterwave->save();

        // Create BulkClix for Payouts
        $bulkclixPayout = new PaymentGatewayConfig();
        $bulkclixPayout->gateway_name = PaymentGatewayConfig::GATEWAY_BULKCLIX;
        $bulkclixPayout->gateway_type = PaymentGatewayConfig::TYPE_PAYOUT;
        $bulkclixPayout->is_active = true;
        $bulkclixPayout->is_default = true;
        $bulkclixPayout->environment = env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX;
        $bulkclixPayout->config_data = [
            'api_key' => env('BULKCLIX_API_KEY', ''),
            'base_url' => env('BULKCLIX_BASE_URL', 'https://api.bulkclix.com/api/v1'),
            'sender_id' => env('BULKCLIX_SENDER_ID', 'XTRA4U'),
        ];
        $bulkclixPayout->supported_features = [
            'mtn_momo' => true,
            'telecel_momo' => true,
            'airteltigo_momo' => true,
        ];
        $bulkclixPayout->save();

        // Create BulkClix for SMS
        $bulkclixSms = new PaymentGatewayConfig();
        $bulkclixSms->gateway_name = PaymentGatewayConfig::GATEWAY_BULKCLIX;
        $bulkclixSms->gateway_type = PaymentGatewayConfig::TYPE_SMS;
        $bulkclixSms->is_active = true;
        $bulkclixSms->is_default = true;
        $bulkclixSms->environment = env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX;
        $bulkclixSms->config_data = [
            'api_key' => env('BULKCLIX_API_KEY', ''),
            'base_url' => env('BULKCLIX_BASE_URL', 'https://api.bulkclix.com/api/v1'),
            'sender_id' => env('BULKCLIX_SENDER_ID', 'XTRA4U'),
        ];
        $bulkclixSms->supported_features = [
            'sms' => true,
            'bulk_sms' => true,
        ];
        $bulkclixSms->save();

        // Create Hubtel for Payment Collection (inactive by default)
        PaymentGatewayConfig::firstOrCreate(
            [
                'gateway_name' => PaymentGatewayConfig::GATEWAY_HUBTEL,
                'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            ],
            [
                'is_active' => false,
                'is_default' => false,
                'environment' => env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX,
                'config_data' => [
                    'client_id' => env('HUBTEL_CLIENT_ID', ''),
                    'client_secret' => env('HUBTEL_CLIENT_SECRET', ''),
                    'username' => env('HUBTEL_USERNAME', ''),
                    'password' => env('HUBTEL_PASSWORD', ''),
                    'base_url' => env('HUBTEL_BASE_URL', 'https://api.hubtel.com'),
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'airteltigo_momo' => true,
                    'vodafone_cash' => true,
                    'bank_transfer' => true,
                ],
            ]
        );

        // Create Hubtel for Payouts (inactive by default)
        PaymentGatewayConfig::firstOrCreate(
            [
                'gateway_name' => PaymentGatewayConfig::GATEWAY_HUBTEL,
                'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            ],
            [
                'is_active' => false,
                'is_default' => false,
                'environment' => env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX,
                'config_data' => [
                    'client_id' => env('HUBTEL_CLIENT_ID', ''),
                    'client_secret' => env('HUBTEL_CLIENT_SECRET', ''),
                    'username' => env('HUBTEL_USERNAME', ''),
                    'password' => env('HUBTEL_PASSWORD', ''),
                    'base_url' => env('HUBTEL_BASE_URL', 'https://api.hubtel.com'),
                ],
                'supported_features' => [
                    'mtn_momo' => true,
                    'airteltigo_momo' => true,
                    'vodafone_cash' => true,
                ],
            ]
        );

        // Create Hubtel for SMS (inactive by default)
        PaymentGatewayConfig::firstOrCreate(
            [
                'gateway_name' => PaymentGatewayConfig::GATEWAY_HUBTEL,
                'gateway_type' => PaymentGatewayConfig::TYPE_SMS,
            ],
            [
                'is_active' => false,
                'is_default' => false,
                'environment' => env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX,
                'config_data' => [
                    'client_id' => env('HUBTEL_CLIENT_ID', ''),
                    'client_secret' => env('HUBTEL_CLIENT_SECRET', ''),
                    'username' => env('HUBTEL_USERNAME', ''),
                    'password' => env('HUBTEL_PASSWORD', ''),
                    'base_url' => env('HUBTEL_BASE_URL', 'https://api.hubtel.com'),
                ],
                'supported_features' => [
                    'sms' => true,
                    'bulk_sms' => true,
                ],
            ]
        );
    }
}