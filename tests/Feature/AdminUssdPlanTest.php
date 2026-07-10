<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUssdPlanTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Growth',
            'description' => 'Mid-tier plan.',
            'price' => 150.00,
            'included_sessions' => 10000,
            'duration_days' => 30,
            'extension_code' => '99',
            'display_order' => 5,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_plan_index_lists_seeded_plans(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.ussd-plans.index'))
            ->assertOk()
            ->assertSee('USSD Plans')
            ->assertSee('Starter')
            ->assertSee('Enterprise');
    }

    public function test_admin_can_create_a_plan(): void
    {
        $this->actingAdmin();

        $this->post(route('admin.ussd-plans.store'), $this->validPayload())
            ->assertRedirect(route('admin.ussd-plans.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ussd_plans', [
            'name' => 'Growth',
            'extension_code' => '99',
            'included_sessions' => 10000,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('ussd_subscription_events', [
            'event' => UssdSubscriptionEvent::ADMIN_PLAN_CREATED,
            'actor_type' => 'admin',
        ]);
    }

    public function test_duplicate_extension_code_is_rejected(): void
    {
        $this->actingAdmin();

        $this->post(route('admin.ussd-plans.store'), $this->validPayload(['extension_code' => '45']))
            ->assertSessionHasErrors('extension_code');

        $this->assertDatabaseMissing('ussd_plans', ['name' => 'Growth']);
    }

    public function test_extension_code_must_be_numeric(): void
    {
        $this->actingAdmin();

        $this->post(route('admin.ussd-plans.store'), $this->validPayload(['extension_code' => '4a']))
            ->assertSessionHasErrors('extension_code');
    }

    public function test_admin_can_update_a_plan(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $this->put(route('admin.ussd-plans.update', $plan), $this->validPayload([
            'name' => 'Starter Plus',
            'extension_code' => '45',
            'price' => 95.00,
        ]))->assertRedirect(route('admin.ussd-plans.index'));

        $this->assertDatabaseHas('ussd_plans', ['id' => $plan->id, 'name' => 'Starter Plus', 'price' => 95.00]);
    }

    public function test_plan_keeps_its_own_extension_code_on_update(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '46')->firstOrFail();

        $this->put(route('admin.ussd-plans.update', $plan), $this->validPayload([
            'name' => 'Business',
            'extension_code' => '46',
        ]))->assertSessionHasNoErrors();
    }

    public function test_omitting_is_active_on_update_deactivates_the_plan(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();
        $this->assertTrue($plan->is_active);

        $payload = $this->validPayload(['name' => 'Starter', 'extension_code' => '45']);
        unset($payload['is_active']);

        $this->put(route('admin.ussd-plans.update', $plan), $payload);

        $this->assertFalse($plan->fresh()->is_active);
    }

    public function test_admin_can_toggle_plan_status(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $this->patch(route('admin.ussd-plans.toggle-active', $plan))->assertRedirect();
        $this->assertFalse($plan->fresh()->is_active);

        $this->patch(route('admin.ussd-plans.toggle-active', $plan))->assertRedirect();
        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_admin_can_delete_a_plan_without_live_subscriptions(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '47')->firstOrFail();

        $this->delete(route('admin.ussd-plans.destroy', $plan))
            ->assertRedirect(route('admin.ussd-plans.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('ussd_plans', ['id' => $plan->id]);
    }

    public function test_plan_with_live_subscriptions_cannot_be_deleted(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();
        $vendor = Vendor::factory()->create();

        UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => Str::uuid()->toString(),
        ]);

        $this->delete(route('admin.ussd-plans.destroy', $plan))
            ->assertSessionHasErrors('error');

        $this->assertNotSoftDeleted('ussd_plans', ['id' => $plan->id]);
    }

    public function test_deleted_plan_keeps_its_extension_code_reserved(): void
    {
        $this->actingAdmin();
        $plan = UssdPlan::where('extension_code', '47')->firstOrFail();
        $this->delete(route('admin.ussd-plans.destroy', $plan));

        $this->post(route('admin.ussd-plans.store'), $this->validPayload(['extension_code' => '47']))
            ->assertSessionHasErrors('extension_code');
    }

    public function test_non_admin_cannot_manage_plans(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));

        $this->get(route('admin.ussd-plans.index'))->assertForbidden();
        $this->post(route('admin.ussd-plans.store'), $this->validPayload())->assertForbidden();
    }

    public function test_guest_cannot_manage_plans(): void
    {
        $this->get(route('admin.ussd-plans.index'))->assertRedirect(route('admin.login'));
    }
}
