<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3 payment reconciliation — cross-gateway coverage.
 *
 * PaymentReconciliationService contains zero provider-specific logic; it
 * only ever calls GatewayManager::verifyCollectionWithGateway(), so this
 * suite exists to prove the SAME normalized SUCCESS/FAILED/PENDING/UNKNOWN
 * behavior holds for every live collection gateway's actual response shape
 * — Paystack and Payaza already have deep coverage elsewhere (Phase 1/2/3
 * Order/AFA/etc. tests); this file covers Flutterwave, BulkClix, and Moolre
 * specifically, using Order as the common vehicle.
 */
class PaymentReconciliationCrossGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function pendingOrder(float $price, string $reference, string $gateway): Order
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);

        return Order::create([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => $price,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => $gateway,
            'payment_reference' => $reference,
        ]);
    }

    protected function service(): PaymentReconciliationService
    {
        return app(PaymentReconciliationService::class);
    }

    // -----------------------------------------------------------------
    // Flutterwave
    // -----------------------------------------------------------------

    protected function makeFlutterwaveConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_FLUTTERWAVE,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => false,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'FLWPUBK_TEST-x',
                'secret_key' => 'FLWSECK_TEST-x',
                'encryption_key' => 'test-encryption-key',
                'payment_url' => 'https://api.flutterwave.com/v3',
            ],
            'supported_features' => [],
        ]);
    }

    public function test_flutterwave_success(): void
    {
        $this->makeFlutterwaveConfig();
        $order = $this->pendingOrder(20.00, 'FLW-SUCCESS', PaymentGatewayConfig::GATEWAY_FLUTTERWAVE);

        Http::fake([
            'https://api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'successful', 'amount' => 20.00],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_flutterwave_failed(): void
    {
        $this->makeFlutterwaveConfig();
        $order = $this->pendingOrder(20.00, 'FLW-FAILED', PaymentGatewayConfig::GATEWAY_FLUTTERWAVE);

        Http::fake([
            'https://api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'failed', 'amount' => 20.00],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_flutterwave_pending(): void
    {
        $this->makeFlutterwaveConfig();
        $order = $this->pendingOrder(20.00, 'FLW-PENDING', PaymentGatewayConfig::GATEWAY_FLUTTERWAVE);

        Http::fake([
            'https://api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'pending', 'amount' => 20.00],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_flutterwave_network_failure_stays_unresolved(): void
    {
        $this->makeFlutterwaveConfig();
        $order = $this->pendingOrder(20.00, 'FLW-TIMEOUT', PaymentGatewayConfig::GATEWAY_FLUTTERWAVE);

        Http::fake([
            'https://api.flutterwave.com/v3/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // BulkClix
    // -----------------------------------------------------------------

    protected function makeBulkClixConfig(): void
    {
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
    }

    public function test_bulkclix_success(): void
    {
        $this->makeBulkClixConfig();
        $order = $this->pendingOrder(20.00, 'BCX-SUCCESS', PaymentGatewayConfig::GATEWAY_BULKCLIX);

        Http::fake([
            'https://api.bulkclix.com/api/v1/payment-api/checkstatus/*' => Http::response([
                'message' => 'status fetch Successfully',
                'data' => ['status' => 'success', 'amount' => 20.00],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_bulkclix_failed(): void
    {
        $this->makeBulkClixConfig();
        $order = $this->pendingOrder(20.00, 'BCX-FAILED', PaymentGatewayConfig::GATEWAY_BULKCLIX);

        Http::fake([
            'https://api.bulkclix.com/api/v1/payment-api/checkstatus/*' => Http::response([
                'message' => 'status fetch Successfully',
                'data' => ['status' => 'failed', 'amount' => 20.00],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_bulkclix_pending(): void
    {
        $this->makeBulkClixConfig();
        $order = $this->pendingOrder(20.00, 'BCX-PENDING', PaymentGatewayConfig::GATEWAY_BULKCLIX);

        Http::fake([
            'https://api.bulkclix.com/api/v1/payment-api/checkstatus/*' => Http::response([
                'message' => 'status fetch Successfully',
                'data' => ['status' => 'pending', 'amount' => 20.00],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_bulkclix_malformed_response_stays_unresolved(): void
    {
        $this->makeBulkClixConfig();
        $order = $this->pendingOrder(20.00, 'BCX-MALFORMED', PaymentGatewayConfig::GATEWAY_BULKCLIX);

        Http::fake([
            'https://api.bulkclix.com/api/v1/payment-api/checkstatus/*' => Http::response('<not>json</not>', 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // Moolre
    // -----------------------------------------------------------------

    protected function makeMoolreConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => true,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'api_user' => 'test-api-user',
                'api_key' => 'test-api-key',
                'public_key' => 'test-public-key',
                'account_number' => '1234567890',
                'business_email' => 'ops@xtra4u.test',
                'webhook_secret' => 'test-webhook-secret',
                'currency' => 'GHS',
                'base_url' => 'https://api.moolre.com',
            ],
            'supported_features' => [],
        ]);
    }

    public function test_moolre_success(): void
    {
        $this->makeMoolreConfig();
        $order = $this->pendingOrder(20.00, 'MLR-SUCCESS', PaymentGatewayConfig::GATEWAY_MOOLRE);

        Http::fake([
            'https://api.moolre.com/open/transact/status' => Http::response([
                'status' => 1,
                'data' => ['status' => 'success', 'amount' => 20.00, 'externalref' => 'MLR-SUCCESS'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_moolre_failed(): void
    {
        $this->makeMoolreConfig();
        $order = $this->pendingOrder(20.00, 'MLR-FAILED', PaymentGatewayConfig::GATEWAY_MOOLRE);

        Http::fake([
            'https://api.moolre.com/open/transact/status' => Http::response([
                'status' => 1,
                'data' => ['status' => 'failed', 'amount' => 20.00, 'externalref' => 'MLR-FAILED'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_moolre_pending(): void
    {
        $this->makeMoolreConfig();
        $order = $this->pendingOrder(20.00, 'MLR-PENDING', PaymentGatewayConfig::GATEWAY_MOOLRE);

        Http::fake([
            'https://api.moolre.com/open/transact/status' => Http::response([
                'status' => 1,
                'data' => ['status' => 'pending', 'amount' => 20.00, 'externalref' => 'MLR-PENDING'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_moolre_http_500_stays_unresolved(): void
    {
        $this->makeMoolreConfig();
        $order = $this->pendingOrder(20.00, 'MLR-500', PaymentGatewayConfig::GATEWAY_MOOLRE);

        Http::fake([
            'https://api.moolre.com/open/transact/status' => Http::response('Internal Server Error', 500),
        ]);

        $outcome = $this->service()->reconcile($order);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }
}
