<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutPayerPhoneRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_allows_missing_payer_phone_for_redirect_gateways(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'supports_webhook' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);

        $vendor = Vendor::factory()->create();

        Http::fake(function ($request) {
            if ((string) $request->url() === 'https://api.paystack.co/transaction/initialize') {
                return Http::response([
                    'status' => true,
                    'message' => 'Initialized',
                    'data' => [
                        'authorization_url' => 'https://paystack.example/redirect',
                        'access_code' => 'ACCESS_123',
                        'reference' => 'REF_123',
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $payload = [
            'vendor_id' => $vendor->id,
            'category_id' => 'test',
            'service_id' => 'svc_test',
            'service_name' => 'Test Service',
            'package_id' => 'pkg_test',
            'package_name' => 'Test Package',
            'service_purchased' => 'Test Package',
            'amount' => 1.50,
            'recipient_phone' => '0240000000',
            // Intentionally omit payer_phone for redirect gateways.
            'is_reseller_product' => 0,
            'reseller_product_id' => null,
            'original_product_id' => null,
        ];

        $resp = $this->postJson(route('checkout.process'), $payload);

        $resp->assertOk();
        $resp->assertJson([
            'success' => true,
            'redirect' => 'https://paystack.example/redirect',
        ]);
    }

    public function test_checkout_allows_missing_payer_phone_for_inline_gateways_and_uses_recipient_phone(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => true,
            'supports_webhook' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'api_key' => 'test-bulkclix-key',
                'base_url' => 'https://api.bulkclix.com/api/v1',
            ],
            'supported_features' => [],
        ]);

        $vendor = Vendor::factory()->create();

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if ($url === 'https://api.bulkclix.com/api/v1/payment-api/momopay') {
                // Ensure we are using the recipient phone as the payment phone when payer_phone is omitted.
                $this->assertSame('0240000000', data_get($request->data(), 'phone_number'));

                return Http::response([
                    'message' => 'Payment Initiated Successful',
                    'data' => [
                        'amount' => 1.5,
                        'transaction_id' => data_get($request->data(), 'transaction_id'),
                        'ext_transaction_id' => 'EXT-123',
                        'phone_number' => data_get($request->data(), 'phone_number'),
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $payload = [
            'vendor_id' => $vendor->id,
            'category_id' => 'test',
            'service_id' => 'svc_test',
            'service_name' => 'Test Service',
            'package_id' => 'pkg_test',
            'package_name' => 'Test Package',
            'service_purchased' => 'Test Package',
            'amount' => 1.50,
            'recipient_phone' => '0240000000',
            // Intentionally omit payer_phone.
            'is_reseller_product' => 0,
            'reseller_product_id' => null,
            'original_product_id' => null,
        ];

        $resp = $this->postJson(route('checkout.process'), $payload);

        $resp->assertOk();
        $resp->assertJson([
            'success' => true,
        ]);
		$this->assertNotEmpty($resp->json('reference'));
		$this->assertNotEmpty($resp->json('verify_url'));
		$this->assertNull($resp->json('redirect'));
    }
}
