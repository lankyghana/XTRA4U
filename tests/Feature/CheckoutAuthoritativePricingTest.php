<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Security regression suite: CheckoutController::process() must never trust
 * the client-submitted `amount`. The authoritative price always comes from
 * the server-resolved Product/ResellerProduct row (vendor-matched), never
 * from the browser.
 */
class CheckoutAuthoritativePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(): void
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

    protected function product(Vendor $vendor, float $price, string $name = 'MTN 1GB'): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    protected function fakePaystackInitialize(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Initialized',
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);
    }

    protected function basePayload(Vendor $vendor, Product $product, float $submittedAmount): array
    {
        return [
            'vendor_id' => $vendor->id,
            'category_id' => 'data',
            'service_id' => 'svc_test',
            'package_id' => 'pkg_test',
            'amount' => $submittedAmount,
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 0,
            'original_product_id' => $product->id,
        ];
    }

    // 1. Customer changes amount from GHS 50 to GHS 1 -> authoritative GHS 50 is used.
    public function test_tampered_low_amount_is_ignored_in_favour_of_authoritative_price(): void
    {
        $this->makePaystackConfig();
        $this->fakePaystackInitialize();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 50.00);

        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, 1.00));

        $resp->assertOk()->assertJson(['success' => true]);
        $order = Order::latest('id')->first();
        $this->assertEquals(50.00, (float) $order->amount_paid);
    }

    // 2. Customer changes amount to 0 -> authoritative price is used (not a free order).
    public function test_zero_amount_does_not_produce_a_free_order(): void
    {
        $this->makePaystackConfig();
        $this->fakePaystackInitialize();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 20.00);

        // Request validation requires amount >= 0.1, so a literal 0 is rejected
        // at the input layer — but the real protection is that even a passing
        // value is never trusted for the actual charge.
        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, 0));
        $resp->assertStatus(422);
        $this->assertNull(Order::latest('id')->first());
    }

    // 3. Negative amount -> rejected outright.
    public function test_negative_amount_is_rejected(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 20.00);

        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, -5));

        $resp->assertStatus(422);
        $this->assertNull(Order::latest('id')->first());
    }

    // 4. Decimal manipulation cannot change the authoritative price.
    public function test_decimal_manipulated_amount_cannot_change_authoritative_price(): void
    {
        $this->makePaystackConfig();
        $this->fakePaystackInitialize();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 18.40);

        // 0.18 still passes the legacy `min:0.1` format check but is a decimal-
        // shifted forgery of the real 18.40 price.
        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, 0.18));

        $resp->assertOk()->assertJson(['success' => true]);
        $order = Order::latest('id')->first();
        $this->assertEquals(18.40, (float) $order->amount_paid);
    }

    // 5a. Customer swaps original_product_id to a different product's id while
    // sending that cheaper product's amount -> server charges the SELECTED
    // product's real price (not the amount sent, not the other product's price).
    public function test_switching_to_a_different_products_id_uses_that_products_own_price(): void
    {
        $this->makePaystackConfig();
        $this->fakePaystackInitialize();
        $vendor = Vendor::factory()->create();
        $cheapProduct = $this->product($vendor, 2.00, 'Cheap Bundle');
        $expensiveProduct = $this->product($vendor, 100.00, 'Expensive Bundle');

        // Attacker submits the expensive product's id but the cheap product's price.
        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $expensiveProduct, 2.00));

        $resp->assertOk()->assertJson(['success' => true]);
        $order = Order::latest('id')->first();
        $this->assertEquals(100.00, (float) $order->amount_paid);
        $this->assertSame($expensiveProduct->id, $order->vendor_service_id);
    }

    // 5b. Customer pairs a valid vendor_id with a DIFFERENT vendor's product id
    // (cross-vendor mismatch) -> rejected outright, price never derived from it.
    public function test_cross_vendor_product_mismatch_is_rejected(): void
    {
        $this->makePaystackConfig();
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $vendorBsCheapProduct = $this->product($vendorB, 1.00, 'Vendor B cheap product');

        $resp = $this->postJson(route('checkout.process'), array_merge(
            $this->basePayload($vendorA, $vendorBsCheapProduct, 1.00),
            ['vendor_id' => $vendorA->id]
        ));

        $resp->assertStatus(422);
        $this->assertNull(Order::latest('id')->first());
    }

    // 6. Vendor storefront custom pricing: two vendors sell the "same" product
    // at different self-set prices — each order must use its own vendor's price.
    public function test_vendor_specific_custom_pricing_is_respected(): void
    {
        $this->makePaystackConfig();
        $this->fakePaystackInitialize();
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $productA = $this->product($vendorA, 15.00, 'MTN 1GB');
        $productB = $this->product($vendorB, 22.50, 'MTN 1GB');

        $respA = $this->postJson(route('checkout.process'), $this->basePayload($vendorA, $productA, 15.00));
        $respA->assertOk();
        $orderA = Order::latest('id')->first();
        $this->assertEquals(15.00, (float) $orderA->amount_paid);

        $respB = $this->postJson(route('checkout.process'), $this->basePayload($vendorB, $productB, 22.50));
        $respB->assertOk();
        $orderB = Order::latest('id')->first();
        $this->assertEquals(22.50, (float) $orderB->amount_paid);
    }

    // Reseller pricing: selling_price (base + markup) is authoritative, not the
    // client amount, and the reseller listing must belong to the submitted vendor.
    public function test_reseller_selling_price_is_authoritative(): void
    {
        $this->makePaystackConfig();
        $this->fakePaystackInitialize();
        $ownerVendor = Vendor::factory()->create();
        $resellerVendor = Vendor::factory()->create();
        $ownedProduct = $this->product($ownerVendor, 10.00, 'Owner product');

        $resellerProduct = ResellerProduct::create([
            'product_id' => $ownedProduct->id,
            'reseller_vendor_id' => $resellerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'base_price' => 10.00,
            'markup_price' => 3.00,
            'is_active' => true,
        ]);

        $resp = $this->postJson(route('checkout.process'), [
            'vendor_id' => $resellerVendor->id,
            'category_id' => 'data',
            'service_id' => 'svc_test',
            'package_id' => 'pkg_test',
            'amount' => 1.00, // tampered — should be ignored
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 1,
            'reseller_product_id' => $resellerProduct->id,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);
        $order = Order::latest('id')->first();
        $this->assertEquals(13.00, (float) $order->amount_paid);
    }

    // 7. Payaza's SDK checkout_config must carry exactly the authoritative amount.
    public function test_payaza_checkout_config_carries_the_authoritative_amount(): void
    {
        $this->makePayazaConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 33.00);

        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, 1.00));

        $resp->assertOk()->assertJson(['success' => true]);
        $this->assertEquals(33.00, $resp->json('checkout_config.checkout_amount'));

        $order = Order::latest('id')->first();
        $this->assertEquals(33.00, (float) $order->amount_paid);
    }

    // 8. Existing gateways (Paystack) receive exactly the authoritative amount
    // over the wire, never the tampered client amount.
    public function test_paystack_receives_exactly_the_authoritative_amount(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 27.75);

        $capturedAmount = null;
        Http::fake(function ($request) use (&$capturedAmount) {
            if ((string) $request->url() === 'https://api.paystack.co/transaction/initialize') {
                $capturedAmount = data_get($request->data(), 'amount');

                return Http::response([
                    'status' => true,
                    'message' => 'Initialized',
                    'data' => ['authorization_url' => 'https://paystack.example/redirect'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        // Client sends a tampered amount far below the real price.
        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, 0.50));

        $resp->assertOk();
        // Paystack amounts are in kobo/pesewas (amount * 100).
        $this->assertSame(2775, $capturedAmount);
    }

    // 9. A gateway-confirmed amount lower than the stored order amount must
    // never be fulfilled (covers the case where the gateway itself, not the
    // checkout request, is the source of a mismatched amount — see
    // PayazaPaymentCollectionTest::test_checkout_verify_endpoint_refuses_amount_mismatch
    // for the Payaza-specific version of this).
    public function test_gateway_confirmed_amount_below_expected_never_fulfils(): void
    {
        $this->makePayazaConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 40.00);

        $resp = $this->postJson(route('checkout.process'), $this->basePayload($vendor, $product, 40.00));
        $resp->assertOk();
        $reference = $resp->json('reference');

        Http::fake([
            'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*' => Http::response([
                'response_code' => '00',
                'transaction_reference' => $reference,
                'transaction_amount' => 1.00, // attacker only actually paid GHS 1
                'transaction_status' => 'Completed',
            ], 200),
        ]);

        $verify = $this->postJson(route('checkout.verify'), ['reference' => $reference]);

        $verify->assertOk()->assertJson(['success' => true, 'status' => 'failed']);
        $this->assertSame('unpaid', Order::latest('id')->first()->payment_status);
    }
}
