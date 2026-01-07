<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkClixPaymentCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulkclix_can_initiate_momo_collection_and_verify_status(): void
    {
        // Configure BulkClix as default collection gateway.
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => false,
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

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 2.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
        ]);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if ($url === 'https://api.bulkclix.com/api/v1/payment-api/momopay') {
                return Http::response([
                    'message' => 'Payment Initiated Successful',
                    'data' => [
                        'amount' => 2,
                        'transaction_id' => data_get($request->data(), 'transaction_id'),
                        'ext_transaction_id' => 'EXT-123',
                        'phone_number' => data_get($request->data(), 'phone_number'),
                    ],
                ], 200);
            }

            if (str_starts_with($url, 'https://api.bulkclix.com/api/v1/payment-api/checkstatus/')) {
                return Http::response([
                    'message' => 'status fetch Successfully',
                    'data' => [
                        'status' => 'success',
                        'transaction_id' => basename($url),
                        'ext_transaction_id' => 'EXT-123',
                        'amount' => '2.00',
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $paymentService = new PaymentService();
        $init = $paymentService->initiatePayment($order, 'test@example.com', 2.00);

        $this->assertTrue($init['success']);
        $this->assertNotEmpty($init['reference']);

        $verification = $paymentService->checkPaymentStatus($init['reference']);
        $this->assertTrue($verification['success']);
        $this->assertSame('success', strtolower((string) data_get($verification, 'data.status')));
    }
}
