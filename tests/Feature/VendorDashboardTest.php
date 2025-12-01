<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VendorDashboardTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function test_vendor_dashboard_loads_for_approved_vendor()
    {
        $vendor = \App\Models\Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard');
        $response->assertStatus(200);
        $response->assertSee($vendor->name);
        $response->assertSee('Sales Summary');
        $response->assertSee('Earnings Summary');
        $response->assertSee('Order List');
        $response->assertSee('Product Management');
        $response->assertSee('Your Store Link');
    }
}
