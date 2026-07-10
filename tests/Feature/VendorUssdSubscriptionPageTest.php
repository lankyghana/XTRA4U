<?php

namespace Tests\Feature;

use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorUssdSubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    private function starter(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    private function activeSubscription(Vendor $vendor, UssdPlan $plan, array $overrides = []): UssdSubscription
    {
        return UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'ussd_code' => UssdSubscription::buildUssdCode('*203*', $plan->extension_code, $vendor->id),
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'activated_at' => now(),
            'expires_at' => now()->addDays(20),
            'payment_reference' => Str::uuid()->toString(),
        ], $overrides));
    }

    public function test_page_lists_active_plans_for_a_vendor_without_a_subscription(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk()
            ->assertSee('You have no USSD subscription')
            ->assertSee('Starter')
            ->assertSee('Business')
            ->assertSee('Enterprise')
            ->assertSee('Subscribe');
    }

    public function test_inactive_plans_are_not_offered(): void
    {
        $vendor = Vendor::factory()->create();
        UssdPlan::where('extension_code', '47')->update(['is_active' => false]);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk()
            ->assertSee('Starter')
            ->assertDontSee('Enterprise');
    }

    public function test_page_shows_current_code_usage_and_expiry(): void
    {
        $vendor = Vendor::factory()->create();
        $this->activeSubscription($vendor, $this->starter(), ['used_sessions' => 1000]);

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.ussd.subscription.index'));

        $response->assertOk()
            ->assertSee('Current Subscription')
            ->assertSee("*203*45*{$vendor->id}#")
            ->assertSee('20% used')
            ->assertSee('Renew');

        // Total 5,000 / used 1,000 / remaining 4,000 all rendered.
        $response->assertSee('5,000')->assertSee('1,000')->assertSee('4,000');
    }

    public function test_exhausted_subscription_shows_a_renewal_warning(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();
        $this->activeSubscription($vendor, $plan, [
            'total_sessions' => 100,
            'used_sessions' => 100,
        ]);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk()
            ->assertSee('Your sessions are exhausted.');
    }

    public function test_subscription_expiring_soon_shows_a_warning(): void
    {
        $vendor = Vendor::factory()->create();
        $this->activeSubscription($vendor, $this->starter(), ['expires_at' => now()->addDays(3)]);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk()
            ->assertSee('expires in 3 days');
    }

    public function test_a_vendor_never_sees_another_vendors_subscription(): void
    {
        $owner = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $plan = $this->starter();

        $this->activeSubscription($owner, $plan, ['used_sessions' => 42]);

        $response = $this->actingAs($other, 'vendor')->get(route('vendor.ussd.subscription.index'));

        $response->assertOk()
            ->assertSee('You have no USSD subscription')
            ->assertDontSee("*203*45*{$owner->id}#");
    }

    public function test_history_only_contains_the_authenticated_vendors_rows(): void
    {
        $owner = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $plan = $this->starter();

        // Other vendor's expired subscription must never leak into owner's history.
        $this->activeSubscription($other, $plan, [
            'status' => UssdSubscription::STATUS_EXPIRED,
            'current_for_vendor_id' => null,
            'ussd_code' => null,
            'price_paid' => 999.99,
        ]);

        $this->activeSubscription($owner, $plan);

        $this->actingAs($owner, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk()
            ->assertDontSee('999.99');
    }

    public function test_guest_is_redirected_to_vendor_login(): void
    {
        $this->get(route('vendor.ussd.subscription.index'))
            ->assertRedirect(route('vendor.login.form'));
    }

    public function test_unapproved_vendor_cannot_reach_the_page(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertRedirect(route('vendor.login.form'));
    }

    public function test_upgrade_and_switch_labels_reflect_relative_price(): void
    {
        $vendor = Vendor::factory()->create();
        $this->activeSubscription($vendor, $this->starter()); // GHS 80

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk()
            ->assertSee('Upgrade')   // Business (250) and Enterprise (800)
            ->assertSee('Renew');    // Starter itself
    }
}
