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

class EcgPlatformPageTest extends TestCase
{
    use RefreshDatabase;

    private function ecgProduct(Vendor $vendor, string $name = 'ECG Prepaid Token', float $price = 20.00, string $category = 'ecg'): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => json_encode(['category' => $category, 'service' => 'ECG']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Missing / invalid configuration
    // -------------------------------------------------------------------------

    public function test_shows_unavailable_page_when_no_vendor_is_assigned(): void
    {
        $this->get(route('services.ecg'))
            ->assertStatus(503)
            ->assertSee('ECG Unavailable');
    }

    public function test_shows_unavailable_page_when_assigned_vendor_is_unapproved(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);
        $this->ecgProduct($vendor);
        PlatformServiceVendor::setVendorFor('ecg', $vendor->id);

        $this->get(route('services.ecg'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_has_no_active_ecg_products(): void
    {
        $vendor = Vendor::factory()->create();
        $this->ecgProduct($vendor, 'MTN 1GB Bundle', 5.00, 'data');
        PlatformServiceVendor::setVendorFor('ecg', $vendor->id);

        $this->get(route('services.ecg'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_ecg_category_is_admin_closed(): void
    {
        $vendor = Vendor::factory()->create();
        $this->ecgProduct($vendor);
        PlatformServiceVendor::setVendorFor('ecg', $vendor->id);

        ServiceAvailability::setOpen('ecg', false);
        ServiceAvailability::setMessage('ECG payments are paused for maintenance.');

        $this->get(route('services.ecg'))
            ->assertStatus(503)
            ->assertSee('ECG payments are paused for maintenance.');
    }

    // -------------------------------------------------------------------------
    // Happy path: correct vendor's products, and only that vendor's, and only
    // that category's — separately assigned from Data Bundles.
    // -------------------------------------------------------------------------

    public function test_shows_the_assigned_vendors_ecg_products_only(): void
    {
        $assignedVendor = Vendor::factory()->create();
        $this->ecgProduct($assignedVendor, 'ECG Prepaid Token');
        PlatformServiceVendor::setVendorFor('ecg', $assignedVendor->id);

        $otherVendor = Vendor::factory()->create();
        $this->ecgProduct($otherVendor, 'Other Vendor ECG Token');

        $this->get(route('services.ecg'))
            ->assertStatus(200)
            ->assertSee('ECG')
            ->assertSee('ECG Prepaid Token')
            ->assertDontSee('Other Vendor ECG Token');
    }

    public function test_page_includes_the_how_it_works_and_why_xtra4u_sections(): void
    {
        $vendor = Vendor::factory()->create();
        $this->ecgProduct($vendor);
        PlatformServiceVendor::setVendorFor('ecg', $vendor->id);

        $this->get(route('services.ecg'))
            ->assertOk()
            ->assertSee('Three steps. Under 5 minutes.')
            ->assertSee('Enter Meter Number')
            ->assertSee('Instant Delivery')
            ->assertSee('Secure Payments');
    }

    public function test_data_bundles_and_ecg_can_be_assigned_to_different_vendors_independently(): void
    {
        $dataVendor = Vendor::factory()->create();
        $this->ecgProduct($dataVendor, 'MTN 1GB Bundle', 5.00, 'data');
        PlatformServiceVendor::setVendorFor('data', $dataVendor->id);

        $ecgVendor = Vendor::factory()->create();
        $this->ecgProduct($ecgVendor, 'ECG Prepaid Token', 20.00, 'ecg');
        PlatformServiceVendor::setVendorFor('ecg', $ecgVendor->id);

        $this->get(route('services.data-bundles'))
            ->assertSee('MTN 1GB Bundle')
            ->assertDontSee('ECG Prepaid Token');

        $this->get(route('services.ecg'))
            ->assertSee('ECG Prepaid Token')
            ->assertDontSee('MTN 1GB Bundle');
    }

    // -------------------------------------------------------------------------
    // Existing vendor storefront is unaffected.
    // -------------------------------------------------------------------------

    public function test_vendor_storefront_still_shows_full_catalog_regardless_of_platform_assignment(): void
    {
        $vendor = Vendor::factory()->create();
        $this->ecgProduct($vendor, 'ECG Prepaid Token', 20.00, 'ecg');
        $this->ecgProduct($vendor, 'MTN 1GB Bundle', 5.00, 'data');

        $this->get(route('storefront.vendor', $vendor->vendor_code))
            ->assertStatus(200)
            ->assertSee('ECG Prepaid Token')
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
        $product = $this->ecgProduct($vendor, 'ECG Prepaid Token', 20.00);
        PlatformServiceVendor::setVendorFor('ecg', $vendor->id);

        $this->get(route('services.ecg'))
            ->assertStatus(200)
            ->assertSee('ECG Prepaid Token');

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
            'category_id' => 'ecg',
            'service_id' => 'svc_ecg',
            'service_name' => 'ECG',
            'package_id' => 'product_'.$product->id,
            'package_name' => 'ECG Prepaid Token',
            'amount' => 20.00,
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 0,
            'original_product_id' => $product->id,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame($product->id, $order->vendor_service_id);
        $this->assertEquals(20.00, (float) $order->amount_paid);
    }
}
