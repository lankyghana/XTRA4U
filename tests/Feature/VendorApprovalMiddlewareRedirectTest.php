<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorApprovalMiddlewareRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_vendor_login_when_accessing_vendor_routes(): void
    {
        $response = $this->get(route('vendor.dashboard'));

        $response->assertRedirect(route('vendor.login.form'));
    }

    public function test_unapproved_vendor_is_logged_out_and_redirected_to_vendor_login(): void
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => false,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->get(route('vendor.dashboard'));

        $response->assertRedirect(route('vendor.login.form'));
        $this->assertGuest('vendor');
    }
}
