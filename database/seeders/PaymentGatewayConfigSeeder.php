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
        $paystack->supports_collection = true;
        $paystack->supports_generic = true;
        $paystack->supports_payout = true;
        $paystack->supports_sms = false;
        $paystack->supports_webhook = false;
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
        $flutterwave->supports_collection = true;
        $flutterwave->supports_generic = true;
        $flutterwave->supports_payout = true;
        $flutterwave->supports_sms = false;
        $flutterwave->supports_webhook = false;
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
        $bulkclixPayout->supports_collection = false;
        $bulkclixPayout->supports_generic = false;
        $bulkclixPayout->supports_payout = true;
        $bulkclixPayout->supports_sms = true;
        $bulkclixPayout->supports_webhook = false;
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
        $bulkclixSms->supports_collection = false;
        $bulkclixSms->supports_generic = false;
        $bulkclixSms->supports_payout = true;
        $bulkclixSms->supports_sms = true;
        $bulkclixSms->supports_webhook = false;
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
                'supports_collection' => false,
                'supports_generic' => false,
                'supports_payout' => true,
                'supports_sms' => false,
                'supports_webhook' => false,
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
                'supports_collection' => false,
                'supports_generic' => false,
                'supports_payout' => true,
                'supports_sms' => false,
                'supports_webhook' => false,
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
                'supports_collection' => false,
                'supports_generic' => false,
                'supports_payout' => true,
                'supports_sms' => false,
                'supports_webhook' => false,
                'supports_webhook' => false,
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

        // Create Moolre for Payment Collection (inactive by default)
        PaymentGatewayConfig::firstOrCreate(
            [
                'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            ],
            [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => false,
                'supports_webhook' => true,
                'is_active' => false,
                'is_default' => false,
                'environment' => env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX,
                'config_data' => [
                    'api_user' => env('MOOLRE_API_USER', ''),
                    'public_key' => env('MOOLRE_PUBLIC_KEY', ''),
                    'account_number' => env('MOOLRE_ACCOUNT_NUMBER', ''),
                    'business_email' => env('MOOLRE_BUSINESS_EMAIL', ''),
                    'webhook_secret' => env('MOOLRE_WEBHOOK_SECRET', ''),
                    'currency' => env('MOOLRE_CURRENCY', 'GHS'),
                    'base_url' => env('MOOLRE_BASE_URL', 'https://api.moolre.com'),
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'telecel_momo' => true,
                    'airteltigo_momo' => true,
                    'webhook' => true,
                ],
            ]
        );

        // Create Moolre for Payouts (inactive by default)
        PaymentGatewayConfig::firstOrCreate(
            [
                'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            ],
            [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => false,
                'supports_webhook' => true,
                'is_active' => false,
                'is_default' => false,
                'environment' => env('APP_ENV') === 'production' ? PaymentGatewayConfig::ENV_LIVE : PaymentGatewayConfig::ENV_SANDBOX,
                'config_data' => [
                    'api_user' => env('MOOLRE_API_USER', ''),
                    'api_key' => env('MOOLRE_API_KEY', ''),
                    'account_number' => env('MOOLRE_ACCOUNT_NUMBER', ''),
                    'currency' => env('MOOLRE_CURRENCY', 'GHS'),
                    'base_url' => env('MOOLRE_BASE_URL', 'https://api.moolre.com'),
                ],
                'supported_features' => [
                    'mtn_momo' => true,
                    'telecel_momo' => true,
                    'airteltigo_momo' => true,
                ],
            ]
        );
    }
}