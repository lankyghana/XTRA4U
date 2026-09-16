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

class DataBundlesPlatformPageTest extends TestCase
{
    use RefreshDatabase;

    private function dataProduct(Vendor $vendor, string $name = 'MTN 1GB', float $price = 5.00, string $category = 'data'): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => json_encode(['category' => $category, 'network' => 'MTN', 'size' => '1GB']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Missing / invalid configuration — must fail gracefully, never crash,
    // never silently substitute a different vendor.
    // -------------------------------------------------------------------------

    public function test_shows_unavailable_page_when_no_vendor_is_assigned(): void
    {
        $this->get(route('services.data-bundles'))
            ->assertStatus(503)
            ->assertSee('Data Bundles Unavailable')
            ->assertSee('This service is temporarily unavailable. Please try again later.');
    }

    public function test_shows_unavailable_page_when_assigned_vendor_is_unapproved(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);
        $this->dataProduct($vendor);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->get(route('services.data-bundles'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_is_deleted(): void
    {
        $vendor = Vendor::factory()->create();
        PlatformServiceVendor::setVendorFor('data', $vendor->id);
        $vendor->delete();

        $this->get(route('services.data-bundles'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_has_no_active_data_products(): void
    {
        $vendor = Vendor::factory()->create();
        // Product exists but is inactive.
        Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => 5.00,
            'is_active' => false,
        ]);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->get(route('services.data-bundles'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_only_has_other_category_products(): void
    {
        $vendor = Vendor::factory()->create();
        $this->dataProduct($vendor, 'ECG Token', 10.00, 'ecg');
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->get(route('services.data-bundles'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_data_category_is_admin_closed(): void
    {
        $vendor = Vendor::factory()->create();
        $this->dataProduct($vendor);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        ServiceAvailability::setOpen('data', false);
        ServiceAvailability::setMessage('Data bundles are paused for maintenance.');

        $this->get(route('services.data-bundles'))
            ->assertStatus(503)
            ->assertSee('Data bundles are paused for maintenance.');
    }

    // -------------------------------------------------------------------------
    // Happy path: correct vendor's products, and only that vendor's.
    // -------------------------------------------------------------------------

    public function test_shows_the_assigned_vendors_data_products_only(): void
    {
        $assignedVendor = Vendor::factory()->create();
        $this->dataProduct($assignedVendor, 'MTN 1GB Bundle', 5.00);
        PlatformServiceVendor::setVendorFor('data', $assignedVendor->id);

        // A different, unassigned vendor with its own data product — must
        // never appear on the platform page.
        $otherVendor = Vendor::factory()->create();
        $this->dataProduct($otherVendor, 'Telecel 2GB Bundle', 7.00);

        $response = $this->get(route('services.data-bundles'));

        $response->assertStatus(200)
            ->assertSee('Data Bundles')
            ->assertSee('MTN 1GB Bundle')
            ->assertDontSee('Telecel 2GB Bundle');
    }

    public function test_page_includes_the_how_it_works_and_why_xtra4u_sections(): void
    {
        $vendor = Vendor::factory()->create();
        $this->dataProduct($vendor);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->get(route('services.data-bundles'))
            ->assertOk()
            ->assertSee('Three steps. Under 5 minutes.')
            ->assertSee('Enter Recipient Number')
            ->assertSee('Instant Delivery')
            ->assertSee('Secure Payments');
    }

    public function test_does_not_expose_the_assigned_vendors_other_category_products(): void
    {
        $vendor = Vendor::factory()->create();
        $this->dataProduct($vendor, 'MTN 1GB Bundle', 5.00, 'data');
        $this->dataProduct($vendor, 'ECG Prepaid Token', 20.00, 'ecg');
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        $this->get(route('services.data-bundles'))
            ->assertStatus(200)
            ->assertSee('MTN 1GB Bundle')
            ->assertDontSee('ECG Prepaid Token');
    }

    public function test_changing_the_assigned_vendor_changes_the_platform_pages_provider(): void
    {
        $vendorA = Vendor::factory()->create();
        $this->dataProduct($vendorA, 'Vendor A Bundle');
        PlatformServiceVendor::setVendorFor('data', $vendorA->id);

        $this->get(route('services.data-bundles'))->assertSee('Vendor A Bundle');

        $vendorB = Vendor::factory()->create();
        $this->dataProduct($vendorB, 'Vendor B Bundle');
        PlatformServiceVendor::setVendorFor('data', $vendorB->id);

        $this->get(route('services.data-bundles'))
            ->assertSee('Vendor B Bundle')
            ->assertDontSee('Vendor A Bundle');
    }

    // -------------------------------------------------------------------------
    // Existing vendor storefront is unaffected by this feature.
    // -------------------------------------------------------------------------

    public function test_vendor_storefront_still_shows_full_catalog_regardless_of_platform_assignment(): void
    {
        $vendor = Vendor::factory()->create();
        $this->dataProduct($vendor, 'MTN 1GB Bundle', 5.00, 'data');
        $this->dataProduct($vendor, 'ECG Prepaid Token', 20.00, 'ecg');
        // Deliberately NOT assigned to any platform service.

        $this->get(route('storefront.vendor', $vendor->vendor_code))
            ->assertStatus(200)
            ->assertSee('MTN 1GB Bundle')
            ->assertSee('ECG Prepaid Token');
    }

    public function test_another_vendors_storefront_is_unaffected_by_the_platform_assignment(): void
    {
        $assignedVendor = Vendor::factory()->create();
        $this->dataProduct($assignedVendor, 'Assigned Vendor Bundle');
        PlatformServiceVendor::setVendorFor('data', $assignedVendor->id);

        $unrelatedVendor = Vendor::factory()->create();
        $this->dataProduct($unrelatedVendor, 'Unrelated Vendor Bundle');

        $this->get(route('storefront.vendor', $unrelatedVendor->vendor_code))
            ->assertStatus(200)
            ->assertSee('Unrelated Vendor Bundle')
            ->assertDontSee('Assigned Vendor Bundle');
    }

    // -------------------------------------------------------------------------
    // Full customer journey: purchase reuses the existing checkout stack and
    // is attributed to the correct (admin-assigned) vendor.
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
        $product = $this->dataProduct($vendor, 'MTN 1GB Bundle', 5.00);
        PlatformServiceVendor::setVendorFor('data', $vendor->id);

        // 1) Homepage-originated entry point renders the assigned vendor's product.
        $this->get(route('services.data-bundles'))
            ->assertStatus(200)
            ->assertSee('MTN 1GB Bundle');

        // 2) The page's checkout form posts to the same, unmodified commerce
        // endpoint the vendor storefront uses, with the resolved vendor's id.
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
            'category_id' => 'data',
            'service_id' => 'svc_mtn',
            'service_name' => 'MTN',
            'package_id' => 'product_'.$product->id,
            'package_name' => 'MTN 1GB Bundle',
            'amount' => 5.00,
            'recipient_phone' => '0244000000',
            'is_reseller_product' => 0,
            'original_product_id' => $product->id,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        // 3) Order attribution, price, and product id are all exactly as the
        // existing commerce stack would produce for a /store/{vendor} sale.
        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame($product->id, $order->vendor_service_id);
        $this->assertEquals(5.00, (float) $order->amount_paid);
    }
}
