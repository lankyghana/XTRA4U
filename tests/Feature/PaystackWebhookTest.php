<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\PaystackPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        PaymentGatewayConfig::create([
            'gateway_name' => 'paystack',
            'gateway_type' => 'payment_collection',
            'is_active' => true,
            'is_default' => true,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_abc',
                'payment_url' => 'https://api.paystack.co',
            ],
        ]);
    }

    public function test_rejects_missing_signature()
    {
        $payload = ['event' => 'charge.success'];
        
        $response = $this->postJson(route('webhooks.paystack'), $payload);
        
        $response->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Invalid signature']);
    }

    public function test_rejects_invalid_signature()
    {
        $payload = ['event' => 'charge.success'];
        
        $response = $this->postJson(route('webhooks.paystack'), $payload, [
            'x-paystack-signature' => 'invalid-hash'
        ]);
        
        $response->assertStatus(403);
    }

    public function test_ignores_non_charge_success_events()
    {
        $payload = ['event' => 'transfer.success'];
        $signature = hash_hmac('sha512', json_encode($payload), 'sk_test_abc');

        $response = $this->withHeaders([
            'x-paystack-signature' => $signature
        ])->postJson(route('webhooks.paystack'), $payload);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Event ignored']);
    }

    public function test_processes_wallet_topup()
    {
        $vendor = Vendor::factory()->create();
        $reference = 'XTRA4U-TOPUP-123';
        
        $topup = WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendor->id,
            'amount' => 50,
            'status' => 'initiated',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
            ]
        ];
        
        // Mock the Paystack API verify call
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'status' => 'success',
                    'amount' => 5000, // 50 GHS in kobo
                ]
            ], 200)
        ]);

        $signature = hash_hmac('sha512', json_encode($payload), 'sk_test_abc');

        $response = $this->withHeaders([
            'x-paystack-signature' => $signature
        ])->postJson(route('webhooks.paystack'), $payload);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Wallet Top-up processed']);

        $this->assertEquals('completed', $topup->fresh()->status);
        $this->assertEquals(50, $vendor->fresh()->wallet_balance);
    }

    public function test_processes_afa_registration()
    {
        $vendor = Vendor::factory()->create();
        $registration = \App\Models\AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'vendor_network_id' => 1,
            'full_name' => 'John Doe',
            'phone' => '0244123456',
            'id_type' => 'Ghana Card',
            'id_number' => 'GHA-123456789-0',
            'reference' => 'XTRA4U-AFA-456',
            'payment_reference' => 'XTRA4U-AFA-456',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'XTRA4U-AFA-456',
            ]
        ];
        
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => 10000,
                ]
            ], 200)
        ]);

        $signature = hash_hmac('sha512', json_encode($payload), 'sk_test_abc');

        $response = $this->withHeaders([
            'x-paystack-signature' => $signature
        ])->postJson(route('webhooks.paystack'), $payload);

        $response->assertStatus(200)
            ->assertJson(['message' => 'AFA Registration processed']);

        $this->assertEquals('completed', $registration->fresh()->payment_status);
    }
}
