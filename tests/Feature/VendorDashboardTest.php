<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
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
        $response->assertSeeText('Sales Overview');
        $response->assertSeeText('Total Earnings (Net)');
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

    public function test_vendor_dashboard_sales_stats_includes_afa_earnings()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'reseller_vendor_id' => null,
            'full_name' => 'Test Customer',
            'id_number' => 'GHA-123456789-0',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0241234567',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 100.00,
            'vendor_price' => 100.00,
            'platform_commission' => 2.00,
            'vendor_earning' => 98.00,
            'reseller_earning' => 0.00,
            'is_reseller_order' => false,
            'payment_reference' => 'PAYREF-TEST-001',
            'payment_gateway' => 'test',
            'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'payment_completed_at' => now(),
            'status' => AfaRegistration::STATUS_PENDING,
            'reference' => 'AFA-TEST-0001',
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard/sales-stats?filter=all_time', [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'earnings' => '98.00',
        ]);
    }
}
