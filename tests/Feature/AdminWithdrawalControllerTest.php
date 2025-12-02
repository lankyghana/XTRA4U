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
            'status' => VendorWithdrawal::STATUS_PENDING,
            'reference' => Str::upper(Str::random(10)),
        ]);

        $response = $this->get(route('admin.withdrawals.index'));

        $response->assertOk();
        $response->assertSee($vendor->name);
        $response->assertSee('120.50');
    }

    public function test_admin_can_mark_withdrawal_processing_and_approve(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create();
        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 75,
            'status' => VendorWithdrawal::STATUS_PENDING,
            'reference' => Str::upper(Str::random(10)),
        ]);

        $this->from(route('admin.withdrawals.index'))
            ->post(route('admin.withdrawals.processing', $withdrawal))
            ->assertRedirect(route('admin.withdrawals.index'));

        $this->assertEquals(VendorWithdrawal::STATUS_PROCESSING, $withdrawal->fresh()->status);

        $this->from(route('admin.withdrawals.index'))
            ->post(route('admin.withdrawals.approve', $withdrawal))
            ->assertRedirect(route('admin.withdrawals.index'));

        $this->assertEquals(VendorWithdrawal::STATUS_APPROVED, $withdrawal->fresh()->status);
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create();
        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 45,
            'status' => VendorWithdrawal::STATUS_PENDING,
            'reference' => Str::upper(Str::random(10)),
        ]);

        $response = $this->from(route('admin.withdrawals.index'))
            ->post(route('admin.withdrawals.reject', $withdrawal), [
                'notes' => 'Bank details missing',
            ]);

        $response->assertRedirect(route('admin.withdrawals.index'));
        $withdrawal->refresh();
        $this->assertEquals(VendorWithdrawal::STATUS_REJECTED, $withdrawal->status);
        $this->assertEquals('Bank details missing', $withdrawal->notes);
    }

    public function test_reject_requires_reason(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create();
        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 30,
            'status' => VendorWithdrawal::STATUS_PENDING,
            'reference' => Str::upper(Str::random(10)),
        ]);

        $response = $this->from(route('admin.withdrawals.index'))
            ->post(route('admin.withdrawals.reject', $withdrawal), []);

        $response->assertRedirect(route('admin.withdrawals.index'));
        $response->assertSessionHasErrors('notes');
        $this->assertEquals(VendorWithdrawal::STATUS_PENDING, $withdrawal->fresh()->status);
    }
}
