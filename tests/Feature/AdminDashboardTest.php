<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
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

    public function test_admin_dashboard_shows_sum_of_all_vendor_balances(): void
    {
        $this->actingAdmin();

        Vendor::factory()->create([
            'wallet_balance' => 100.25,
        ]);

        Vendor::factory()->create([
            'wallet_balance' => 49.75,
        ]);

        $response = $this->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSeeText('Total Vendor Balances');
        $response->assertSeeText('GHS 150.00');
    }
}
