<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminVendorTierAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): Admin
    {
        // The admin.only middleware authenticates via the default (User) guard,
        // while the controller records the acting admin via the 'admin' guard.
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $admin = Admin::factory()->create();
        Auth::guard('admin')->login($admin);

        return $admin;
    }

    private function regularTier(): VendorTier
    {
        return VendorTier::firstOrCreate(
            ['slug' => 'regular'],
            ['name' => 'Regular', 'priority' => 0, 'discount_type' => 'percentage', 'discount_value' => 0, 'is_active' => true]
        );
    }

    private function silverTier(): VendorTier
    {
        return VendorTier::firstOrCreate(
            ['slug' => 'silver'],
            ['name' => 'Silver', 'priority' => 1, 'discount_type' => 'percentage', 'discount_value' => 5, 'is_active' => true]
        );
    }

    public function test_admin_can_manually_promote_vendor_tier(): void
    {
        $admin = $this->actingAdmin();
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create(['is_approved' => true, 'tier_id' => $regular->id]);

        $this->post(route('admin.vendors.update-tier', $vendor), [
            'tier_id' => $silver->id,
            'notes' => 'Manual bump.',
        ])->assertRedirect();

        $vendor->refresh();
        $this->assertEquals($silver->id, $vendor->tier_id);
        $this->assertEquals($admin->id, $vendor->tier_reviewed_by);
        $this->assertNotNull($vendor->tier_reviewed_at);

        $this->assertDatabaseHas('vendor_tier_histories', [
            'vendor_id' => $vendor->id,
            'previous_tier_id' => $regular->id,
            'new_tier_id' => $silver->id,
            'action' => 'promoted',
            'admin_id' => $admin->id,
            'notes' => 'Manual bump.',
        ]);
    }

    public function test_admin_can_manually_demote_vendor_tier(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create(['is_approved' => true, 'tier_id' => $silver->id]);

        $this->post(route('admin.vendors.update-tier', $vendor), [
            'tier_id' => $regular->id,
        ])->assertRedirect();

        $vendor->refresh();
        $this->assertEquals($regular->id, $vendor->tier_id);
        $this->assertDatabaseHas('vendor_tier_histories', [
            'vendor_id' => $vendor->id,
            'previous_tier_id' => $silver->id,
            'new_tier_id' => $regular->id,
            'action' => 'demoted',
        ]);
    }

    public function test_manual_tier_change_clears_pending_eligibility(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'tier_id' => $regular->id,
            'eligible_for_tier_id' => $silver->id,
            'is_tier_eligible' => true,
            'tier_qualified_at' => now(),
        ]);

        $this->post(route('admin.vendors.update-tier', $vendor), [
            'tier_id' => $silver->id,
        ])->assertRedirect();

        $vendor->refresh();
        $this->assertFalse($vendor->is_tier_eligible);
        $this->assertNull($vendor->eligible_for_tier_id);
    }

    public function test_setting_same_tier_is_a_noop(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();

        $vendor = Vendor::factory()->create(['is_approved' => true, 'tier_id' => $regular->id]);

        $this->post(route('admin.vendors.update-tier', $vendor), [
            'tier_id' => $regular->id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('vendor_tier_histories', ['vendor_id' => $vendor->id]);
    }

    public function test_cannot_set_tier_for_unapproved_vendor(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create(['is_approved' => false, 'tier_id' => $regular->id]);

        $this->post(route('admin.vendors.update-tier', $vendor), [
            'tier_id' => $silver->id,
        ])->assertSessionHasErrors('tier_id');

        $this->assertEquals($regular->id, $vendor->fresh()->tier_id);
    }

    public function test_tier_id_must_exist(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();

        $vendor = Vendor::factory()->create(['is_approved' => true, 'tier_id' => $regular->id]);

        $this->post(route('admin.vendors.update-tier', $vendor), [
            'tier_id' => 999999,
        ])->assertSessionHasErrors('tier_id');
    }

    public function test_change_tier_control_visible_for_approved_vendor(): void
    {
        $this->actingAdmin();
        $this->regularTier();

        Vendor::factory()->create(['is_approved' => true, 'name' => 'Approved Vendor']);

        $this->get(route('admin.vendors.index'))
            ->assertOk()
            ->assertSee('Change Tier');
    }
}
