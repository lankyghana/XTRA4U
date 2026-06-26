<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTier;
use App\Models\VendorTierHistory;
use App\Models\VendorTierRule;
use App\Services\VendorTierQualificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorTierTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        return $admin;
    }

    private function makeAdmin(): Admin
    {
        return Admin::factory()->create();
    }

    private function regularTier(): VendorTier
    {
        return VendorTier::firstOrCreate(
            ['slug' => 'regular'],
            ['name' => 'Regular', 'priority' => 0, 'discount_type' => 'percentage', 'discount_value' => 0, 'is_active' => true]
        );
    }

    private function silverTier(int $minOrders = 10): VendorTier
    {
        $tier = VendorTier::firstOrCreate(
            ['slug' => 'silver'],
            ['name' => 'Silver', 'priority' => 1, 'discount_type' => 'percentage', 'discount_value' => 5, 'is_active' => true]
        );

        // Replace all existing rules so the test controls the threshold.
        $tier->rules()->delete();
        VendorTierRule::create(['tier_id' => $tier->id, 'rule_key' => 'min_affiliate_orders', 'operator' => '>=', 'value' => $minOrders]);

        return $tier;
    }

    // ─── Admin CRUD ───────────────────────────────────────────────────────────

    public function test_admin_can_view_vendor_tiers_index(): void
    {
        $this->actingAdmin();
        $this->regularTier();

        $this->get(route('admin.vendor-tiers.index'))
            ->assertOk()
            ->assertSee('Regular');
    }

    public function test_admin_can_create_tier(): void
    {
        $this->actingAdmin();

        $this->post(route('admin.vendor-tiers.store'), [
            'name' => 'Gold',
            'priority' => 2,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.vendor-tiers.index'));

        $this->assertDatabaseHas('vendor_tiers', ['name' => 'Gold', 'discount_value' => 10]);
    }

    public function test_admin_can_update_tier_and_rules(): void
    {
        $this->actingAdmin();
        $tier = $this->regularTier();

        $this->put(route('admin.vendor-tiers.update', $tier), [
            'name' => 'Regular Updated',
            'priority' => 0,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'is_active' => '1',
            'rules' => [
                ['rule_key' => 'min_account_age_days', 'operator' => '>=', 'value' => '30'],
            ],
        ])->assertRedirect(route('admin.vendor-tiers.index'));

        $this->assertDatabaseHas('vendor_tiers', ['name' => 'Regular Updated']);
        $this->assertDatabaseHas('vendor_tier_rules', ['rule_key' => 'min_account_age_days', 'value' => 30]);
    }

    public function test_cannot_delete_tier_with_assigned_vendors(): void
    {
        $this->actingAdmin();
        $tier = $this->regularTier();
        Vendor::factory()->create(['tier_id' => $tier->id]);

        $this->delete(route('admin.vendor-tiers.destroy', $tier))
            ->assertRedirect()
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('vendor_tiers', ['id' => $tier->id]);
    }

    // ─── Qualification ────────────────────────────────────────────────────────

    public function test_vendor_becomes_eligible_when_rules_met(): void
    {
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create(['tier_id' => $regular->id, 'is_approved' => true]);

        // Stub the orders query via direct DB seeding
        // Create 10+ completed orders for this vendor
        for ($i = 0; $i < 10; $i++) {
            \App\Models\Order::create([
                'vendor_id' => $vendor->id,
                'recipient_phone_number' => '0550000000',
                'mobile_money_number' => '0550000000',
                'mobile_money_network' => 'MTN',
                'service_purchased' => 'Data',
                'amount_paid' => 10,
                'status' => 'completed',
                'payment_status' => 'completed',
            ]);
        }

        $service = app(VendorTierQualificationService::class);
        $service->evaluateVendor($vendor);
        $vendor->refresh();

        $this->assertTrue($vendor->is_tier_eligible);
        $this->assertEquals($silver->id, $vendor->eligible_for_tier_id);
    }

    public function test_vendor_not_eligible_when_rules_not_met(): void
    {
        $regular = $this->regularTier();
        $this->silverTier();

        $vendor = Vendor::factory()->create(['tier_id' => $regular->id, 'is_approved' => true]);

        $service = app(VendorTierQualificationService::class);
        $service->evaluateVendor($vendor);
        $vendor->refresh();

        $this->assertFalse($vendor->is_tier_eligible);
    }

    // ─── Promotion workflow ───────────────────────────────────────────────────

    public function test_admin_can_approve_promotion(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create([
            'tier_id' => $regular->id,
            'eligible_for_tier_id' => $silver->id,
            'is_tier_eligible' => true,
            'tier_qualified_at' => now(),
        ]);

        $admin = Admin::factory()->create();
        \Illuminate\Support\Facades\Auth::guard('admin')->login($admin);

        $this->post(route('admin.vendor-tier-promotions.approve', $vendor), [
            'notes' => 'Well deserved.',
        ])->assertRedirect();

        $vendor->refresh();
        $this->assertEquals($silver->id, $vendor->tier_id);
        $this->assertFalse($vendor->is_tier_eligible);
        $this->assertNull($vendor->eligible_for_tier_id);
        $this->assertDatabaseHas('vendor_tier_histories', [
            'vendor_id' => $vendor->id,
            'action' => 'promoted',
            'new_tier_id' => $silver->id,
        ]);
    }

    public function test_admin_can_reject_promotion(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create([
            'tier_id' => $regular->id,
            'eligible_for_tier_id' => $silver->id,
            'is_tier_eligible' => true,
            'tier_qualified_at' => now(),
        ]);

        $admin = Admin::factory()->create();
        \Illuminate\Support\Facades\Auth::guard('admin')->login($admin);

        $this->post(route('admin.vendor-tier-promotions.reject', $vendor), [
            'notes' => 'Not yet.',
        ])->assertRedirect();

        $vendor->refresh();
        $this->assertEquals($regular->id, $vendor->tier_id);
        $this->assertFalse($vendor->is_tier_eligible);
        $this->assertDatabaseHas('vendor_tier_histories', [
            'vendor_id' => $vendor->id,
            'action' => 'rejected',
        ]);
    }

    // ─── Base tier assignment ─────────────────────────────────────────────────

    public function test_vendor_assigned_regular_tier_on_approval(): void
    {
        $this->actingAdmin();
        $regular = $this->regularTier();
        $admin = Admin::factory()->create();

        $vendor = Vendor::factory()->create(['is_approved' => false, 'tier_id' => null]);

        $this->post(route('admin.vendors.approve', $vendor));

        $vendor->refresh();
        $this->assertEquals($regular->id, $vendor->tier_id);
    }

    // ─── Discount rate ────────────────────────────────────────────────────────

    public function test_tier_discount_rate_returns_fraction(): void
    {
        $silver = $this->silverTier();

        $this->assertEquals(0.05, $silver->getDiscountRate());
    }

    public function test_vendor_apply_tier_discount(): void
    {
        $silver = $this->silverTier();

        $vendor = Vendor::factory()->create(['tier_id' => $silver->id]);
        $vendor->setRelation('tier', $silver);

        $this->assertEquals(95.0, $vendor->applyTierDiscount(100.0));
    }

    // ─── History audit log ────────────────────────────────────────────────────

    public function test_tier_history_page_loads(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.vendor-tier-history.index'))
            ->assertOk();
    }
}
