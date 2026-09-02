<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfaRegistrationPlatformPageTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Missing / invalid configuration
    // -------------------------------------------------------------------------

    public function test_shows_unavailable_page_when_no_vendor_is_assigned(): void
    {
        $this->get(route('services.afa-registration'))
            ->assertStatus(503)
            ->assertSee('AFA Registration Unavailable');
    }

    public function test_shows_unavailable_page_when_assigned_vendor_is_unapproved(): void
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => false,
            'afa_enabled' => true,
            'afa_price' => 15.00,
        ]);
        PlatformServiceVendor::setVendorFor('afa', $vendor->id);

        $this->get(route('services.afa-registration'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_assigned_vendor_has_not_priced_afa(): void
    {
        $vendor = Vendor::factory()->create([
            'afa_enabled' => false,
            'afa_price' => 0,
        ]);
        PlatformServiceVendor::setVendorFor('afa', $vendor->id);

        $this->get(route('services.afa-registration'))->assertStatus(503);
    }

    public function test_shows_unavailable_page_when_afa_category_is_admin_closed(): void
    {
        $vendor = Vendor::factory()->create([
            'afa_enabled' => true,
            'afa_price' => 15.00,
        ]);
        PlatformServiceVendor::setVendorFor('afa', $vendor->id);

        ServiceAvailability::setOpen('afa', false);
        ServiceAvailability::setMessage('AFA registration is paused for maintenance.');

        $this->get(route('services.afa-registration'))
            ->assertStatus(503)
            ->assertSee('AFA registration is paused for maintenance.');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_shows_the_registration_form_for_the_assigned_vendor(): void
    {
        $vendor = Vendor::factory()->create([
            'afa_enabled' => true,
            'afa_price' => 15.00,
        ]);
        PlatformServiceVendor::setVendorFor('afa', $vendor->id);

        $response = $this->get(route('services.afa-registration'));

        $response->assertStatus(200)
            ->assertSee('AFA Registration')
            ->assertSee('GH₵ 15.00')
            ->assertSee(route('afa.store', $vendor->vendor_code), false);
    }

    public function test_page_does_not_expose_the_vendor_name_or_link_back_to_its_storefront(): void
    {
        $vendor = Vendor::factory()->create([
            'name' => 'SuperSecretVendorName',
            'afa_enabled' => true,
            'afa_price' => 15.00,
        ]);
        PlatformServiceVendor::setVendorFor('afa', $vendor->id);

        $this->get(route('services.afa-registration'))
            ->assertOk()
            ->assertDontSee('SuperSecretVendorName')
            ->assertDontSee(route('storefront.vendor', $vendor->vendor_code), false);
    }

    public function test_changing_the_assigned_vendor_changes_the_registration_fee_shown(): void
    {
        $vendorA = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 15.00]);
        PlatformServiceVendor::setVendorFor('afa', $vendorA->id);

        $this->get(route('services.afa-registration'))->assertSee('GH₵ 15.00');

        $vendorB = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 25.00]);
        PlatformServiceVendor::setVendorFor('afa', $vendorB->id);

        $this->get(route('services.afa-registration'))->assertSee('GH₵ 25.00');
    }

    // -------------------------------------------------------------------------
    // Existing vendor-scoped AFA page is unaffected.
    // -------------------------------------------------------------------------

    public function test_vendor_scoped_afa_page_still_works_regardless_of_platform_assignment(): void
    {
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 15.00]);
        // Deliberately NOT assigned to the platform page.

        $this->get(route('afa.register', $vendor->vendor_code))
            ->assertStatus(200)
            ->assertSee($vendor->name);
    }

    // -------------------------------------------------------------------------
    // Full registration + purchase journey — reuses AfaRegistrationController
    // unchanged.
    // -------------------------------------------------------------------------

    public function test_full_registration_journey_attributes_it_to_the_assigned_vendor(): void
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

        $vendor = Vendor::factory()->create([
            'afa_enabled' => true,
            'afa_price' => 15.00,
        ]);
        PlatformServiceVendor::setVendorFor('afa', $vendor->id);

        $this->get(route('services.afa-registration'))
            ->assertStatus(200)
            ->assertSee(route('afa.store', $vendor->vendor_code), false);

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

        // This is exactly what the page's copied-verbatim form/script posts.
        $resp = $this->postJson(route('afa.store', $vendor->vendor_code), [
            'full_name' => 'Kofi Mensah',
            'id_type' => 'ghana_card',
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0244000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $registration = AfaRegistration::latest('id')->first();
        $this->assertNotNull($registration);
        $this->assertSame($vendor->id, $registration->vendor_id);
        $this->assertFalse((bool) $registration->is_reseller_order);
        $this->assertEquals(15.00, (float) $registration->amount);
    }
}
