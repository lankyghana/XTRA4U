<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\ResultCheckerOrder;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResultCheckersPlatformPageTest extends TestCase
{
    use RefreshDatabase;

    private function checkerService(string $name = 'WAEC 2026', float $basePrice = 20.00): NetworkService
    {
        return NetworkService::factory()->create([
            'name' => $name,
            'category' => 'results',
            'service_type' => 'results_checker',
            'base_price' => $basePrice,
            'is_active' => true,
        ]);
    }

    private function enableForVendor(Vendor $vendor, NetworkService $service, float $profit = 5.00): VendorResultCheckerSetting
    {
        return VendorResultCheckerSetting::create([
            'vendor_id' => $vendor->id,
            'service_id' => $service->id,
            'profit_amount' => $profit,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Missing / invalid configuration
    // -------------------------------------------------------------------------

    public function test_shows_unavailable_page_when_no_vendor_is_assigned(): void
    {
        $this->get(route('services.result-checkers'))
            ->assertStatus(503)
            ->assertSee('Results Checker Unavailable');
    }

    public function test_shows_unavailable_page_when_assigned_vendor_is_unapproved(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);
        $service = $this->checkerService();
        $this->enableForVendor($vendor, $service);
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        $this->get(route('services.result-checkers'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_has_no_active_checker_settings(): void
    {
        $vendor = Vendor::factory()->create();
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        $this->get(route('services.result-checkers'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_vendor_setting_is_inactive(): void
    {
        $vendor = Vendor::factory()->create();
        $service = $this->checkerService();
        $this->enableForVendor($vendor, $service)->update(['is_active' => false]);
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        $this->get(route('services.result-checkers'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_service_is_not_priced(): void
    {
        $vendor = Vendor::factory()->create();
        $service = $this->checkerService(basePrice: 0);
        $this->enableForVendor($vendor, $service);
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        $this->get(route('services.result-checkers'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_results_category_is_admin_closed(): void
    {
        $vendor = Vendor::factory()->create();
        $service = $this->checkerService();
        $this->enableForVendor($vendor, $service);
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        ServiceAvailability::setOpen('results', false);
        ServiceAvailability::setMessage('Results checkers are paused for maintenance.');

        $this->get(route('services.result-checkers'))
            ->assertStatus(503)
            ->assertSee('Results checkers are paused for maintenance.');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_shows_the_assigned_vendors_checker_services_only(): void
    {
        $assignedVendor = Vendor::factory()->create();
        $service = $this->checkerService('WAEC 2026');
        $this->enableForVendor($assignedVendor, $service);
        PlatformServiceVendor::setVendorFor('results', $assignedVendor->id);

        $otherVendor = Vendor::factory()->create();
        $otherService = $this->checkerService('BECE 2026');
        $this->enableForVendor($otherVendor, $otherService);

        $response = $this->get(route('services.result-checkers'));

        $response->assertStatus(200)
            ->assertSee('Results Checker')
            ->assertSee('WAEC 2026')
            ->assertDontSee('BECE 2026');
    }

    public function test_a_service_with_category_other_than_results_is_excluded(): void
    {
        $vendor = Vendor::factory()->create();
        // A results_checker-type service mistakenly filed under a different
        // storefront category must not appear on the Results Checker page.
        $service = $this->checkerService('WAEC 2026');
        $service->update(['category' => 'data']);
        $this->enableForVendor($vendor, $service);
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        $this->get(route('services.result-checkers'))->assertStatus(503);
    }

    // -------------------------------------------------------------------------
    // Existing vendor storefront is unaffected.
    // -------------------------------------------------------------------------

    public function test_vendor_scoped_result_checkers_page_still_works_regardless_of_platform_assignment(): void
    {
        $vendor = Vendor::factory()->create();
        $service = $this->checkerService('WAEC 2026');
        $this->enableForVendor($vendor, $service);
        // Deliberately NOT assigned to the platform page.

        $this->get(route('storefront.result-checkers', $vendor->vendor_code))
            ->assertStatus(200)
            ->assertSee('WAEC 2026');
    }

    // -------------------------------------------------------------------------
    // Full purchase journey — reuses ResultCheckerCheckoutController unchanged.
    // -------------------------------------------------------------------------

    public function test_full_purchase_journey_attributes_the_order_to_the_assigned_vendor(): void
    {
        \App\Models\PaymentGatewayConfig::create([
            'gateway_name' => \App\Models\PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => \App\Models\PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'supports_webhook' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => \App\Models\PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);

        $vendor = Vendor::factory()->create();
        $service = $this->checkerService('WAEC 2026', 20.00);
        $this->enableForVendor($vendor, $service, 5.00);
        PlatformServiceVendor::setVendorFor('results', $vendor->id);

        $this->get(route('services.result-checkers'))
            ->assertStatus(200)
            ->assertSee('WAEC 2026');

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

        // This is exactly what the shared purchase panel's `submitOrder()`
        // posts for an `is_results_checker` package.
        $resp = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), [
            'service_id' => $service->id,
            'quantity' => 1,
            'customer_phone' => '0244000000',
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $order = ResultCheckerOrder::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame($service->id, $order->service_id);
        $this->assertEquals(25.00, (float) $order->unit_price); // base_price + profit_amount
    }
}
