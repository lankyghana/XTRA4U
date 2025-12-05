<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDashboardTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function test_vendor_dashboard_loads_for_approved_vendor()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard');
        $response->assertStatus(200);
        $response->assertSeeText('Gross Sales');
        $response->assertSee('Wallet & Withdrawals', false);
        $response->assertSeeText('Recent Orders');
        $response->assertSeeText('Quick Actions');
    }

    public function test_vendor_withdrawals_page_loads()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 150.00,
            'status' => VendorWithdrawal::STATUS_PENDING,
            'reference' => 'WITHDRAW123',
            'notes' => 'First request',
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->get('/vendor/withdrawals');

        $response->assertStatus(200);
        $response->assertSeeText('Withdrawals');
        $response->assertSeeText('Withdrawal History');
        $response->assertSeeText('WITHDRAW123');
    }
}
