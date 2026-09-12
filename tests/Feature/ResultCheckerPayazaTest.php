<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResultCheckerPayazaTest extends TestCase
{
    use RefreshDatabase;

    protected const CHECK_STATUS_URL = 'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*';

    private Vendor $vendor;

    private NetworkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();

        $this->service = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'base_price' => 50.00,
            'is_active' => true,
        ]);

        VendorResultCheckerSetting::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $this->service->id,
            'profit_amount' => 10.00,
            'is_active' => true,
        ]);

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

    // Popup precondition + quantity-based authoritative total.
    public function test_checkout_returns_payaza_checkout_config_with_quantity_based_total(): void
    {
        $response = $this->postJson(
            route('result-checkers.checkout', ['vendor' => $this->vendor->vendor_code]),
            [
                'service_id' => $this->service->id,
                'quantity' => 3,
                'customer_phone' => '0241234567',
            ]
        );

        $response->assertOk()->assertJson(['success' => true, 'flow_type' => 'payaza']);
        // unit_price (60) * quantity (3) = 180, never the client's own "amount" (none is even sent here).
        $this->assertEquals(180.00, $response->json('checkout_config.checkout_amount'));
        $this->assertNotEmpty($response->json('verify_url'));
    }

    // JSON callback (the Payaza popup's confirmation step) allocates exactly once.
    public function test_json_callback_allocates_pins_exactly_once(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $this->service->id,
            'customer_phone' => '0241234567',
            'quantity' => 1,
            'unit_price' => 60.00,
            'total_price' => 60.00,
            'vendor_profit' => 10.00,
            'status' => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-PYZ-1',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ]);

        ResultCheckerPin::factory()->create(['service_id' => $this->service->id, 'status' => 'available']);

        $this->fakeCheckStatus('XTRA4U-RC-PYZ-1', 'Completed', 60.00, '00');

        $first = $this->getJson(route('result-checkers.payment.callback', ['order' => $order->id]).'?reference=XTRA4U-RC-PYZ-1');
        $first->assertOk()->assertJson(['success' => true, 'status' => 'success']);
        $this->assertNotEmpty($first->json('redirect'));

        $this->assertSame(1, $order->fresh()->pins()->count());

        $second = $this->getJson(route('result-checkers.payment.callback', ['order' => $order->id]).'?reference=XTRA4U-RC-PYZ-1');
        $second->assertOk()->assertJson(['success' => true, 'status' => 'success']);

        $this->assertSame(1, $order->fresh()->pins()->count(), 'Duplicate callback must not allocate extra pins');
    }

    public function test_amount_mismatch_blocks_fulfillment(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $this->service->id,
            'customer_phone' => '0241234567',
            'quantity' => 1,
            'unit_price' => 60.00,
            'total_price' => 60.00,
            'vendor_profit' => 10.00,
            'status' => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-PYZ-AMT',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ]);

        ResultCheckerPin::factory()->create(['service_id' => $this->service->id, 'status' => 'available']);

        // Attacker only paid GHS 1 against a GHS 60 order.
        $this->fakeCheckStatus('XTRA4U-RC-PYZ-AMT', 'Completed', 1.00, '00');

        $resp = $this->getJson(route('result-checkers.payment.callback', ['order' => $order->id]).'?reference=XTRA4U-RC-PYZ-AMT');

        $resp->assertOk()->assertJson(['success' => true, 'status' => 'failed']);
        $this->assertSame(0, $order->fresh()->pins()->count());
        $this->assertNotSame('completed', $order->fresh()->status);
    }

    // Callback (Payaza popup) + webhook race for the same transaction settle exactly once.
    public function test_callback_and_webhook_race_allocates_exactly_once(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $this->service->id,
            'customer_phone' => '0241234567',
            'quantity' => 1,
            'unit_price' => 60.00,
            'total_price' => 60.00,
            'vendor_profit' => 10.00,
            'status' => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-PYZ-RACE',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ]);

        ResultCheckerPin::factory()->create(['service_id' => $this->service->id, 'status' => 'available']);

        $this->fakeCheckStatus('XTRA4U-RC-PYZ-RACE', 'Completed', 60.00, '00');

        $callback = $this->getJson(route('result-checkers.payment.callback', ['order' => $order->id]).'?reference=XTRA4U-RC-PYZ-RACE');
        $callback->assertOk()->assertJson(['status' => 'success']);

        $webhook = $this->postJson(route('result-checkers.payment.webhook'), ['reference' => 'XTRA4U-RC-PYZ-RACE']);
        $webhook->assertOk();

        $this->assertSame(1, $order->fresh()->pins()->count());
    }

    // Failed payment does not allocate anything (existing behaviour preserved via JSON path).
    public function test_failed_payment_does_not_allocate(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $this->service->id,
            'customer_phone' => '0241234567',
            'quantity' => 1,
            'unit_price' => 60.00,
            'total_price' => 60.00,
            'vendor_profit' => 10.00,
            'status' => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-PYZ-FAIL',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ]);

        $this->fakeCheckStatus('XTRA4U-RC-PYZ-FAIL', 'Failed', 0, '06');

        $resp = $this->getJson(route('result-checkers.payment.callback', ['order' => $order->id]).'?reference=XTRA4U-RC-PYZ-FAIL');

        $resp->assertOk()->assertJson(['success' => true, 'status' => 'failed']);
        $this->assertSame('failed', $order->fresh()->status);
    }

    // A merely pending payment must not be marked failed on the JSON path
    // (the Payaza popup polls repeatedly; a still-settling payment must keep waiting).
    public function test_pending_status_is_not_treated_as_failed_on_json_path(): void
    {
        $order = ResultCheckerOrder::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $this->service->id,
            'customer_phone' => '0241234567',
            'quantity' => 1,
            'unit_price' => 60.00,
            'total_price' => 60.00,
            'vendor_profit' => 10.00,
            'status' => 'pending_payment',
            'payment_reference' => 'XTRA4U-RC-PYZ-PENDING',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ]);

        $this->fakeCheckStatus('XTRA4U-RC-PYZ-PENDING', 'Initialized', 0, '09');

        $resp = $this->getJson(route('result-checkers.payment.callback', ['order' => $order->id]).'?reference=XTRA4U-RC-PYZ-PENDING');

        $resp->assertOk()->assertJson(['success' => true, 'status' => 'pending']);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }
}
