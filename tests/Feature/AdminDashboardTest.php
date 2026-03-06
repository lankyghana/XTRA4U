<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
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

    public function test_admin_dashboard_shows_sum_of_all_vendor_withdrawable_balances(): void
    {
        $this->actingAdmin();

        $vendorOne = Vendor::factory()->create([
            'wallet_balance' => 100.25,
        ]);

        Vendor::factory()->create([
            'wallet_balance' => 49.75,
        ]);

        WalletTopup::create([
            'vendor_id' => $vendorOne->id,
            'amount' => 30.00,
            'consumed' => 5.00,
            'status' => 'completed',
            'reference' => 'TEST-TOPUP-' . uniqid(),
            'metadata' => ['source' => 'test'],
        ]);

        $response = $this->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSeeText('Total Withdrawable Balances');
        $response->assertSeeText('GHS 125.00');
    }
}
