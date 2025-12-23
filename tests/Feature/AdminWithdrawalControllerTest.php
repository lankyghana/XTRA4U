<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminWithdrawalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_can_view_withdrawal_queue(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create();
        VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 120.50,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => Str::upper(Str::random(10)),
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
        ]);

        $response = $this->get(route('admin.withdrawals.index'));

        $response->assertOk();
        $response->assertSee($vendor->name);
        $response->assertSee('120.50');
    }
}
