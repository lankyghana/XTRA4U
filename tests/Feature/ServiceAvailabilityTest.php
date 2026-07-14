<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    // -------------------------------------------------------------------------
    // Helper behaviour
    // -------------------------------------------------------------------------

    public function test_categories_are_open_by_default(): void
    {
        foreach (ServiceAvailability::categories() as $category) {
            $this->assertTrue(ServiceAvailability::isOpen($category), "{$category} should default to open");
        }
    }

    public function test_set_open_persists_and_toggles(): void
    {
        ServiceAvailability::setOpen('data', false);
        $this->assertTrue(ServiceAvailability::isClosed('data'));

        ServiceAvailability::setOpen('data', true);
        $this->assertTrue(ServiceAvailability::isOpen('data'));
    }

    public function test_message_falls_back_to_default_then_uses_custom(): void
    {
        $this->assertSame(ServiceAvailability::DEFAULT_MESSAGE, ServiceAvailability::message());

        ServiceAvailability::setMessage('We are on a short break.');
        $this->assertSame('We are on a short break.', ServiceAvailability::message());
    }

    public function test_category_for_product_reads_metadata_with_default_fallback(): void
    {
        $withCategory = new Product(['description' => json_encode(['category' => 'ecg'])]);
        $this->assertSame('ecg', ServiceAvailability::categoryForProduct($withCategory));

        $withoutCategory = new Product(['description' => 'plain text']);
        $this->assertSame(config('storefront.default_category'), ServiceAvailability::categoryForProduct($withoutCategory));

        $this->assertSame(config('storefront.default_category'), ServiceAvailability::categoryForProduct(null));
    }

    // -------------------------------------------------------------------------
    // Admin settings screen
    // -------------------------------------------------------------------------

    public function test_admin_page_loads(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.settings.service-availability'))
            ->assertStatus(200)
            ->assertSee('Service Availability');
    }

    public function test_admin_can_close_categories_and_set_message(): void
    {
        $this->actingAdmin();

        // Only 'data' is submitted as open — every other category is closed.
        $this->put(route('admin.settings.service-availability.update'), [
            'open' => ['data' => '1'],
            'message' => 'Airtime services are paused for maintenance.',
        ])
            ->assertRedirect(route('admin.settings.service-availability'))
            ->assertSessionHas('success');

        $this->assertTrue(ServiceAvailability::isOpen('data'));
        $this->assertTrue(ServiceAvailability::isClosed('results'));
        $this->assertTrue(ServiceAvailability::isClosed('afa'));
        $this->assertSame('Airtime services are paused for maintenance.', ServiceAvailability::message());
    }

    // -------------------------------------------------------------------------
    // Purchase-endpoint guards
    // -------------------------------------------------------------------------

    public function test_checkout_process_is_blocked_when_data_category_closed(): void
    {
        ServiceAvailability::setOpen('data', false);
        ServiceAvailability::setMessage('Data purchases are closed.');

        $vendor = Vendor::factory()->create();

        // Valid payload with no product => guard resolves the default 'data' category.
        $this->postJson(route('checkout.process'), [
            'vendor_id' => $vendor->id,
            'service_id' => 'svc-1',
            'package_id' => 'pkg-1',
            'amount' => 5.00,
            'recipient_phone' => '0244000000',
        ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Data purchases are closed.',
            ]);
    }

    public function test_result_checker_checkout_is_blocked_when_results_category_closed(): void
    {
        ServiceAvailability::setOpen('results', false);

        $vendor = Vendor::factory()->create();
        $service = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'base_price' => 50.00,
            'is_active' => true,
        ]);
        VendorResultCheckerSetting::create([
            'vendor_id' => $vendor->id,
            'service_id' => $service->id,
            'profit_amount' => 10.00,
            'is_active' => true,
        ]);

        $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), [
            'service_id' => $service->id,
            'quantity' => 1,
            'customer_phone' => '0244000000',
        ])->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_afa_registration_page_shows_maintenance_when_afa_closed(): void
    {
        ServiceAvailability::setOpen('afa', false);
        ServiceAvailability::setMessage('AFA registration is temporarily closed.');

        $vendor = Vendor::factory()->create();

        $this->get(route('afa.register', $vendor->vendor_code))
            ->assertStatus(503)
            ->assertSee('AFA registration is temporarily closed.');
    }
}
