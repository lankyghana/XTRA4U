<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorStorePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_store_renders_with_the_redesigned_chrome(): void
    {
        $vendor = Vendor::factory()->create([
            'name' => 'Ama Data Hub',
            'vendor_code' => 'AMADATA001',
            'is_approved' => true,
        ]);

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]));

        $response->assertOk();
        $response->assertSee('Shop with', false);
        $response->assertSee('Ama Data Hub');
        $response->assertSee('AMADATA001');
        // The page picks up the same design system as the marketplace homepage.
        $response->assertSee('x4-btn', false);
        $response->assertSee('Verified Vendor');
    }

    public function test_unapproved_vendor_does_not_show_the_verified_badge(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);

        $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]))
            ->assertOk()
            ->assertDontSee('Verified Vendor');
    }

    public function test_whatsapp_contact_renders_the_vendor_phone_number(): void
    {
        // `phone_number` is NOT NULL on vendors, so the WhatsApp CTA is
        // effectively always shown; this locks the wa.me link format in.
        $vendor = Vendor::factory()->create(['phone_number' => '0551234567']);

        $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]))
            ->assertOk()
            ->assertSee('Contact vendor: 0551234567')
            ->assertSee('https://wa.me/0551234567', false);
    }

    public function test_category_labels_from_config_are_rendered_as_selectable_tiles(): void
    {
        $vendor = Vendor::factory()->create();

        config(['storefront.categories' => [
            'data' => ['label' => 'Data Bundles', 'icon' => 'signal', 'description' => 'Buy data.'],
            'ecg' => ['label' => 'ECG', 'icon' => 'bolt', 'description' => 'Pay for power.'],
        ]]);

        $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]))
            ->assertOk()
            ->assertSee('Data Bundles')
            ->assertSee('ECG')
            ->assertSee('selectCategory(categories[', false);
    }

    public function test_closed_category_renders_as_disabled_with_the_closed_message(): void
    {
        $vendor = Vendor::factory()->create();

        ServiceAvailability::setOpen('data', false);
        ServiceAvailability::setMessage('Data purchases are paused for maintenance.');

        $response = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]));

        $response->assertOk();
        $response->assertSee('Temporarily closed');
        $response->assertSee('Data purchases are paused for maintenance.', false);
    }
}
