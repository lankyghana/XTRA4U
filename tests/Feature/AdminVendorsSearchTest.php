<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVendorsSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_vendors_can_be_searched_by_name(): void
    {
        $this->actingAdmin();

        Vendor::factory()->create([
            'name' => 'Alpha Vendor',
            'email' => 'alpha@example.com',
            'phone_number' => '0240000000',
            'vendor_code' => 'VNDALPHA',
        ]);

        Vendor::factory()->create([
            'name' => 'Beta Vendor',
            'email' => 'beta@example.com',
            'phone_number' => '0550000000',
            'vendor_code' => 'VNDBETA',
        ]);

        $response = $this->get(route('admin.vendors.index', ['q' => 'Alpha']));

        $response->assertOk();
        $response->assertSee('Alpha Vendor');
        $response->assertDontSee('Beta Vendor');
    }

    public function test_admin_vendors_search_works_with_status_filter(): void
    {
        $this->actingAdmin();

        Vendor::factory()->create([
            'name' => 'Approved Alpha',
            'is_approved' => true,
        ]);

        Vendor::factory()->create([
            'name' => 'Pending Alpha',
            'is_approved' => false,
        ]);

        $response = $this->get(route('admin.vendors.index', ['status' => 'approved', 'q' => 'Alpha']));

        $response->assertOk();
        $response->assertSee('Approved Alpha');
        $response->assertDontSee('Pending Alpha');
    }
}
