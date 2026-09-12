<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfaRegistrationPayazaTest extends TestCase
{
    use RefreshDatabase;

    protected const CHECK_STATUS_URL = 'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*';

    protected function makePayazaConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ]);
    }

    protected function registerPayload(): array
    {
        return [
            'full_name' => 'Test User',
            'id_type' => 'ghana_card',
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Developer',
        ];
    }

    protected function fakeCheckStatus(string $reference, string $status, float $amount, string $responseCode): void
    {
        Http::fake([
            self::CHECK_STATUS_URL => Http::response([
                'response_code' => $responseCode,
                'transaction_reference' => $reference,
                'transaction_amount' => $amount,
                'transaction_status' => $status,
            ], 200),
        ]);
    }

    // Popup precondition: checkout_config present with the server-derived amount.
    public function test_afa_registration_returns_payaza_checkout_config_with_correct_amount(): void
    {
        $this->makePayazaConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $resp = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload());

        $resp->assertOk()->assertJson(['success' => true, 'flow_type' => 'payaza']);
        $this->assertEquals(50.0, $resp->json('checkout_config.checkout_amount'));
        $this->assertSame('GHS', $resp->json('checkout_config.currency_code'));
        $this->assertNotEmpty($resp->json('checkout_config.phone_number'));
    }

    public function test_afa_verify_completes_registration_exactly_once(): void
    {
        $this->makePayazaConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $resp = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload());
        $reference = $resp->json('reference');

        $this->fakeCheckStatus($reference, 'Completed', 50.0, '00');

        $first = $this->postJson(route('afa.verify'), ['reference' => $reference]);
        $first->assertOk()->assertJson(['success' => true, 'status' => 'success']);

        $registration = AfaRegistration::where('payment_reference', $reference)->first();
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->payment_status);

        $second = $this->postJson(route('afa.verify'), ['reference' => $reference]);
        $second->assertOk()->assertJson(['success' => true, 'status' => 'success']);
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->fresh()->payment_status);
    }

    public function test_afa_verify_blocks_fulfillment_on_amount_mismatch(): void
    {
        $this->makePayazaConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $resp = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload());
        $reference = $resp->json('reference');

        // Attacker only actually paid GHS 1 against a GHS 50 registration.
        $this->fakeCheckStatus($reference, 'Completed', 1.0, '00');

        $verify = $this->postJson(route('afa.verify'), ['reference' => $reference]);

        $verify->assertOk()->assertJson(['success' => true, 'status' => 'failed']);
        $registration = AfaRegistration::where('payment_reference', $reference)->first();
        $this->assertNotSame(AfaRegistration::PAYMENT_COMPLETED, $registration->payment_status);
    }

    public function test_afa_verify_does_not_complete_registration_on_failed_payment(): void
    {
        $this->makePayazaConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $resp = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload());
        $reference = $resp->json('reference');

        $this->fakeCheckStatus($reference, 'Failed', 0, '06');

        $verify = $this->postJson(route('afa.verify'), ['reference' => $reference]);

        $verify->assertOk()->assertJson(['success' => true, 'status' => 'failed']);
        $registration = AfaRegistration::where('payment_reference', $reference)->first();
        $this->assertSame(AfaRegistration::PAYMENT_FAILED, $registration->payment_status);
    }
}
