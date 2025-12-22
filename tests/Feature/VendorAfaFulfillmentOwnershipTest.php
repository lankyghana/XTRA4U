<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorAfaFulfillmentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_sees_and_can_manage_reseller_registrations(): void
    {
        $source = Vendor::factory()->create(['is_approved' => true]);
        $reseller = Vendor::factory()->create(['is_approved' => true]);

        $registration = AfaRegistration::create([
            'vendor_id' => $source->id,
            'reseller_vendor_id' => $reseller->id,
            'full_name' => 'Test Customer',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-1',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 100,
            'vendor_price' => 80,
            'platform_commission' => 2,
            'vendor_earning' => 78,
            'reseller_earning' => 20,
            'is_reseller_order' => true,
            'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'status' => AfaRegistration::STATUS_PENDING,
            'reference' => AfaRegistration::generateReference(),
        ]);

        $this->actingAs($reseller, 'vendor');

        $this->get(route('vendor.afa.index'))
            ->assertOk()
            ->assertSee($registration->reference);

        $this->patch(route('vendor.afa.update-status', $registration), [
            'status' => 'processing',
            'notes' => 'ok',
        ])->assertSessionHas('success');

        $this->assertSame(AfaRegistration::STATUS_PROCESSING, $registration->fresh()->status);
    }

    public function test_source_vendor_does_not_manage_reseller_registrations(): void
    {
        $source = Vendor::factory()->create(['is_approved' => true]);
        $reseller = Vendor::factory()->create(['is_approved' => true]);

        $registration = AfaRegistration::create([
            'vendor_id' => $source->id,
            'reseller_vendor_id' => $reseller->id,
            'full_name' => 'Test Customer',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-1',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 100,
            'vendor_price' => 80,
            'platform_commission' => 2,
            'vendor_earning' => 78,
            'reseller_earning' => 20,
            'is_reseller_order' => true,
            'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'status' => AfaRegistration::STATUS_PENDING,
            'reference' => AfaRegistration::generateReference(),
        ]);

        $this->actingAs($source, 'vendor');

        $this->get(route('vendor.afa.index'))
            ->assertOk()
            ->assertDontSee($registration->reference);

        $this->get(route('vendor.afa.show', $registration))
            ->assertForbidden();
    }
}
