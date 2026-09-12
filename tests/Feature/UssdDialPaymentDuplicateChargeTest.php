<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResultCheckerOrder;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 — a fresh discovery scan found that UssdMenuService (the telco
 * USSD *dial* flow — customers buying directly from their phone's USSD menu,
 * NOT the vendor-facing UssdSubscription purchase page) creates Order and
 * ResultCheckerOrder rows and initializes real charges completely outside
 * Phase 4's CheckoutIntentGuard. USSD aggregators are known to retry a
 * request whose response didn't arrive in time, so two near-simultaneous
 * "confirm" deliveries for the SAME dial session could otherwise each place
 * their own order and initialize their own charge.
 */
class UssdDialPaymentDuplicateChargeTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private UssdSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->subscription = UssdSubscription::create([
            'vendor_id' => $this->vendor->id,
            'ussd_plan_id' => UssdPlan::where('extension_code', '45')->firstOrFail()->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $this->vendor->id,
            'ussd_code' => UssdSubscription::buildUssdCode('*203*', '45', $this->vendor->id),
            'extension_code' => '45',
            'price_paid' => 80,
            'total_sessions' => 5000,
            'activated_at' => now(),
            'expires_at' => now()->addDays(20),
            'payment_reference' => Str::uuid()->toString(),
        ]);
    }

    private function makePaystackConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
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
    }

    private function fakeInitializeAlwaysSucceeds(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/transaction/initialize')) {
                $body = $request->data();

                return Http::response([
                    'status' => true,
                    'data' => ['authorization_url' => null, 'reference' => $body['reference'] ?? 'unknown'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    private function dial(string $suffix = ''): string
    {
        $prefix = "45*{$this->vendor->id}";

        return $suffix === '' ? $prefix : "{$prefix}*{$suffix}";
    }

    private function ussd(string $sessionId, array $payload = [])
    {
        return $this->post('/api/ussd', array_merge([
            'sessionId' => $sessionId,
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => $this->dial(),
        ], $payload));
    }

    /** Drive a fresh dial session up to (but not including) the DATA_CONFIRM "1" keypress. */
    private function driveToDataConfirm(string $sessionId, Product $product, string $recipient = '0244111111'): void
    {
        $this->ussd($sessionId); // dial
        $this->ussd($sessionId, ['text' => $this->dial('1')]); // Buy Data Bundle
        $this->ussd($sessionId, ['text' => $this->dial('1*1')]); // Select MTN
        $this->ussd($sessionId, ['text' => $this->dial('1*1*1')]); // Select the (only) bundle
        $this->ussd($sessionId, ['text' => $this->dial('1*1*1*'.$recipient)]); // Enter recipient
    }

    private function product(): Product
    {
        return Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data', 'network' => 'MTN']),
            'price' => 5.00,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------- data bundle flow

    public function test_confirming_a_data_purchase_creates_exactly_one_order(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $product = $this->product();
        $sessionId = 'sess-data-happy';

        $this->driveToDataConfirm($sessionId, $product);
        $response = $this->ussd($sessionId, ['text' => $this->dial('1*1*1*0244111111*1')]);

        $response->assertSee('Order placed successfully', false);
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    public function test_concurrent_confirm_race_never_creates_a_second_order(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $product = $this->product();
        $sessionId = 'sess-data-race';

        $this->driveToDataConfirm($sessionId, $product);

        // Simulate a genuinely concurrent second delivery of the same
        // "confirm" request (aggregator retry) by pre-creating the row the
        // database unique index would have produced for the first delivery,
        // still mid-initiation (no gateway/reference recorded yet — the
        // race happens before that write completes).
        Order::create([
            'recipient_phone_number' => '0244111111',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => (float) $product->price,
            'vendor_id' => $this->vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'idempotency_scope' => CheckoutIntentGuard::scopeForUssdDialSession($sessionId),
            'idempotency_key' => 'ussd-data-order',
        ]);

        $response = $this->ussd($sessionId, ['text' => $this->dial('1*1*1*0244111111*1')]);

        // No crash, no second order, no second gateway call — never an
        // unfriendly constraint error surfaced to the handset.
        $response->assertOk();
        $response->assertDontSee('system error', false);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------ result checker flow

    private function vendorWithResultChecker(): NetworkService
    {
        $service = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'is_active' => true,
            'base_price' => 20.00,
        ]);
        VendorResultCheckerSetting::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $service->id,
            'profit_amount' => 5.00,
            'is_active' => true,
        ]);

        return $service;
    }

    private function driveToResultConfirm(string $sessionId, string $recipient = '0244222222'): void
    {
        $this->ussd($sessionId); // dial
        $this->ussd($sessionId, ['text' => $this->dial('2')]); // Results Checker
        $this->ussd($sessionId, ['text' => $this->dial('2*1')]); // Select the (only) exam type
        $this->ussd($sessionId, ['text' => $this->dial('2*1*1')]); // Quantity: 1
        $this->ussd($sessionId, ['text' => $this->dial('2*1*1*'.$recipient)]); // Recipient phone
    }

    public function test_confirming_a_result_checker_purchase_creates_exactly_one_order(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $this->vendorWithResultChecker();
        $sessionId = 'sess-rc-happy';

        $this->driveToResultConfirm($sessionId);
        $response = $this->ussd($sessionId, ['text' => $this->dial('2*1*1*0244222222*1')]);

        $response->assertSee('Order placed successfully', false);
        $this->assertDatabaseCount('result_checker_orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    public function test_concurrent_confirm_race_never_creates_a_second_result_checker_order(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $service = $this->vendorWithResultChecker();
        $sessionId = 'sess-rc-race';

        $this->driveToResultConfirm($sessionId);

        ResultCheckerOrder::create([
            'vendor_id' => $this->vendor->id,
            'service_id' => $service->id,
            'customer_phone' => '0244222222',
            'customer_name' => 'USSD Customer',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total_price' => 25.00,
            'status' => 'pending_payment',
            'idempotency_scope' => CheckoutIntentGuard::scopeForUssdDialSession($sessionId),
            'idempotency_key' => 'ussd-rc-order',
        ]);

        $response = $this->ussd($sessionId, ['text' => $this->dial('2*1*1*0244222222*1')]);

        $response->assertOk();
        $response->assertDontSee('system error', false);
        $this->assertDatabaseCount('result_checker_orders', 1);
        Http::assertNothingSent();
    }
}
