<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5 — rate limiting for the three payment-initiation routes that had
 * none (checkout.process, purchase, afa.store). result-checkers.checkout,
 * ussd/subscription/purchase, and the wallet top-up routes already had it.
 * No manual rate-limiter reset needed between tests: CACHE_STORE=array in
 * phpunit.xml and Laravel rebuilds the application container per test
 * method, so the in-memory limiter state never leaks across tests.
 */
class PaymentInitiationRateLimitTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_checkout_process_returns_429_once_the_limit_is_exceeded_and_creates_no_orders(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => 5.00,
            'is_active' => true,
        ]);

        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => null, 'reference' => 'r']], 200)]);

        $payload = [
            'vendor_id' => $vendor->id,
            'category_id' => 'data',
            'service_id' => 'svc',
            'package_id' => (string) $product->id,
            'amount' => 5.00,
            'recipient_phone' => '0244000000',
            'original_product_id' => $product->id,
        ];

        // Distinct idempotency keys — a legitimate distinct-purchase burst,
        // not retries of the same intent, so it must actually hit the limit
        // rather than being short-circuited by CheckoutIntentGuard first.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('checkout.process'), $payload + ['idempotency_key' => 'burst-'.$i])
                ->assertOk();
        }

        $blocked = $this->postJson(route('checkout.process'), $payload + ['idempotency_key' => 'burst-final']);

        $blocked->assertStatus(429);
        // No half-created order and no wasted gateway call for the blocked
        // request — the throttle middleware runs before the controller.
        $this->assertDatabaseCount('orders', 20);
        $this->assertCount(20, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    public function test_afa_store_is_rate_limited(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => null, 'reference' => 'r']], 200)]);

        $payload = [
            'full_name' => 'Test User',
            'id_type' => 'ghana_card',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
        ];

        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('afa.store', $vendor->vendor_code), $payload + [
                'id_number' => sprintf('GHA-%09d-0', $i),
                'idempotency_key' => 'afa-burst-'.$i,
            ])->assertOk();
        }

        $this->postJson(route('afa.store', $vendor->vendor_code), $payload + [
            'id_number' => 'GHA-999999999-0',
            'idempotency_key' => 'afa-burst-final',
        ])->assertStatus(429);

        $this->assertDatabaseCount('afa_registrations', 20);
    }

    public function test_purchase_route_is_rate_limited_independently_of_the_internal_quick_buy_call(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => 5.00,
            'is_active' => true,
        ]);

        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => null, 'reference' => 'r']], 200)]);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('purchase'), [
                'recipient_phone_number' => '0244000000',
                'mobile_money_number' => '0244000000',
                'vendor_id' => $vendor->id,
                'vendor_service_id' => $product->id,
                'idempotency_key' => 'purchase-burst-'.$i,
            ])->assertOk();
        }

        $this->postJson(route('purchase'), [
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'idempotency_key' => 'purchase-burst-final',
        ])->assertStatus(429);

        $this->assertDatabaseCount('orders', 20);
    }
}
