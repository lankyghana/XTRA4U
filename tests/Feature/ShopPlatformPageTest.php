<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopPlatformPageTest extends TestCase
{
    use RefreshDatabase;

    private function shopProduct(Vendor $vendor, string $name = 'Netflix Gift Card', float $price = 50.00, string $category = 'shop'): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => json_encode(['category' => $category, 'service' => 'Vouchers']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Missing / invalid configuration
    // -------------------------------------------------------------------------

    public function test_shows_unavailable_page_when_no_vendor_is_assigned(): void
    {
        $this->get(route('services.shop'))
            ->assertStatus(503)
            ->assertSee('Shop Online Unavailable');
    }

    public function test_shows_unavailable_page_when_assigned_vendor_is_unapproved(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);
        $this->shopProduct($vendor);
        PlatformServiceVendor::setVendorFor('shop', $vendor->id);

        $this->get(route('services.shop'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_has_no_active_shop_products(): void
    {
        $vendor = Vendor::factory()->create();
        $this->shopProduct($vendor, 'MTN 1GB Bundle', 5.00, 'data');
        PlatformServiceVendor::setVendorFor('shop', $vendor->id);

        $this->get(route('services.shop'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_shop_category_is_admin_closed(): void
    {
        $vendor = Vendor::factory()->create();
        $this->shopProduct($vendor);
        PlatformServiceVendor::setVendorFor('shop', $vendor->id);

        ServiceAvailability::setOpen('shop', false);
        ServiceAvailability::setMessage('Shop is paused for maintenance.');

        $this->get(route('services.shop'))
            ->assertStatus(503)
            ->assertSee('Shop is paused for maintenance.');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_shows_the_assigned_vendors_shop_products_only(): void
    {
        $assignedVendor = Vendor::factory()->create();
        $this->shopProduct($assignedVendor, 'Netflix Gift Card');
        PlatformServiceVendor::setVendorFor('shop', $assignedVendor->id);

        $otherVendor = Vendor::factory()->create();
        $this->shopProduct($otherVendor, 'Other Vendor Voucher');

        $this->get(route('services.shop'))
            ->assertStatus(200)
            ->assertSee('Shop Online')
            ->assertSee('Netflix Gift Card')
            ->assertDontSee('Other Vendor Voucher');
    }

    public function test_page_includes_the_how_it_works_and_why_xtra4u_sections(): void
    {
        $vendor = Vendor::factory()->create();
        $this->shopProduct($vendor);
        PlatformServiceVendor::setVendorFor('shop', $vendor->id);

        $this->get(route('services.shop'))
            ->assertOk()
            ->assertSee('Three steps. Under 5 minutes.')
            ->assertSee('Choose a Product')
            ->assertSee('Instant Delivery')
            ->assertSee('Secure Payments');
    }

    public function test_all_three_generic_categories_can_be_assigned_independently(): void
    {
        $dataVendor = Vendor::factory()->create();
        $this->shopProduct($dataVendor, 'MTN 1GB Bundle', 5.00, 'data');
        PlatformServiceVendor::setVendorFor('data', $dataVendor->id);

        $ecgVendor = Vendor::factory()->create();
        $this->shopProduct($ecgVendor, 'ECG Prepaid Token', 20.00, 'ecg');
        PlatformServiceVendor::setVendorFor('ecg', $ecgVendor->id);

        $shopVendor = Vendor::factory()->create();
        $this->shopProduct($shopVendor, 'Netflix Gift Card', 50.00, 'shop');
        PlatformServiceVendor::setVendorFor('shop', $shopVendor->id);

        $this->get(route('services.data-bundles'))
            ->assertSee('MTN 1GB Bundle')
            ->assertDontSee('ECG Prepaid Token')
            ->assertDontSee('Netflix Gift Card');

        $this->get(route('services.ecg'))
            ->assertSee('ECG Prepaid Token')
            ->assertDontSee('MTN 1GB Bundle')
            ->assertDontSee('Netflix Gift Card');

        $this->get(route('services.shop'))
            ->assertSee('Netflix Gift Card')
            ->assertDontSee('MTN 1GB Bundle')
            ->assertDontSee('ECG Prepaid Token');
    }

    // -------------------------------------------------------------------------
    // Existing vendor storefront is unaffected.
    // -------------------------------------------------------------------------

    public function test_vendor_storefront_still_shows_full_catalog_regardless_of_platform_assignment(): void
    {
        $vendor = Vendor::factory()->create();
        $this->shopProduct($vendor, 'Netflix Gift Card', 50.00, 'shop');
        $this->shopProduct($vendor, 'MTN 1GB Bundle', 5.00, 'data');

        $this->get(route('storefront.vendor', $vendor->vendor_code))
            ->assertStatus(200)
            ->assertSee('Netflix Gift Card')
            ->assertSee('MTN 1GB Bundle');
    }

    // -------------------------------------------------------------------------
    // Full purchase journey.
    // -------------------------------------------------------------------------

    public function test_full_purchase_journey_attributes_the_order_to_the_assigned_vendor(): void
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
        $product = $this->shopProduct($vendor, 'Netflix Gift Card', 50.00);
        PlatformServiceVendor::setVendorFor('shop', $vendor->id);

        $this->get(route('services.shop'))
            ->assertStatus(200)
            ->assertSee('Netflix Gift Card');

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

        $resp = $this->postJson(route('checkout.process'), [
            'vendor_id' => $vendor->id,
            'category_id' => 'shop',
            'service_id' => 'svc_shop',
            'service_name' => 'Vouchers',
            'package_id' => 'product_'.$product->id,
            'package_name' => 'Netflix Gift Card',
            'amount' => 50.00,
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 0,
            'original_product_id' => $product->id,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame($product->id, $order->vendor_service_id);
        $this->assertEquals(50.00, (float) $order->amount_paid);
    }
}
