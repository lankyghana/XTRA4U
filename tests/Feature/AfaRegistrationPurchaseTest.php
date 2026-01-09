<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfaRegistrationPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_afa_registration_can_initiate_payment_without_vendor_email(): void
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

        $vendor = Vendor::factory()->create([
            // Vendor email can be blank in some real data; gateways like Paystack require a non-empty email.
            // Keep DB constraints satisfied while still exercising the controller fallback behavior.
            'email' => '',
            'afa_enabled' => true,
            'afa_price' => 50,
        ]);

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
            'full_name' => 'Test User',
            'id_type' => 'ghana_card',
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Developer',
        ];

        $resp = $this->post(route('afa.store', $vendor->vendor_code), $payload);

        $resp->assertRedirect('https://paystack.example/redirect');

        $registration = AfaRegistration::query()->first();
        $this->assertNotNull($registration);
        // Generic payments use our generated AFA reference as the payment reference.
        $this->assertSame($registration->reference, $registration->payment_reference);
    }
}
