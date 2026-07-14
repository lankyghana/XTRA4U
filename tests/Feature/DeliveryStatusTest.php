<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    // -------------------------------------------------------------------------
    // Helper behaviour
    // -------------------------------------------------------------------------

    public function test_no_notice_by_default(): void
    {
        $this->assertFalse(DeliveryStatus::isActive());
        $this->assertNull(DeliveryStatus::current());
    }

    public function test_active_notice_with_message_is_returned(): void
    {
        DeliveryStatus::update(true, 'slow', 'Deliveries are slow today.');

        $notice = DeliveryStatus::current();

        $this->assertNotNull($notice);
        $this->assertSame('slow', $notice['level']);
        $this->assertSame('Deliveries are slow today.', $notice['message']);
        $this->assertNotSame('', $notice['version']);
    }

    public function test_active_notice_with_empty_message_is_hidden(): void
    {
        DeliveryStatus::update(true, 'fast', '   ');

        $this->assertNull(DeliveryStatus::current());
    }

    public function test_inactive_notice_is_hidden(): void
    {
        DeliveryStatus::update(false, 'slow', 'Deliveries are slow today.');

        $this->assertNull(DeliveryStatus::current());
    }

    public function test_invalid_level_falls_back_to_default(): void
    {
        DeliveryStatus::update(true, 'lightspeed', 'Hi');

        $this->assertSame(DeliveryStatus::DEFAULT_LEVEL, DeliveryStatus::level());
    }

    public function test_editing_notice_bumps_version(): void
    {
        DeliveryStatus::update(true, 'slow', 'First notice.');
        $first = DeliveryStatus::version();

        // Version is a unix timestamp; ensure a distinct second value.
        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(5));
        DeliveryStatus::update(true, 'fast', 'Second notice.');
        $second = DeliveryStatus::version();
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertNotSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // Admin settings screen
    // -------------------------------------------------------------------------

    public function test_admin_page_loads(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.settings.delivery-status'))
            ->assertStatus(200)
            ->assertSee('Delivery Status');
    }

    public function test_admin_can_post_a_notice(): void
    {
        $this->actingAdmin();

        $this->put(route('admin.settings.delivery-status.update'), [
            'active' => '1',
            'level' => 'slow',
            'message' => 'Data deliveries are running slow due to a provider issue.',
        ])
            ->assertRedirect(route('admin.settings.delivery-status'))
            ->assertSessionHas('success');

        $notice = DeliveryStatus::current();
        $this->assertNotNull($notice);
        $this->assertSame('slow', $notice['level']);
        $this->assertSame('Data deliveries are running slow due to a provider issue.', $notice['message']);
    }

    public function test_admin_can_turn_off_a_notice(): void
    {
        DeliveryStatus::update(true, 'slow', 'Old notice.');

        $this->actingAdmin();

        // 'active' checkbox absent => turned off.
        $this->put(route('admin.settings.delivery-status.update'), [
            'level' => 'slow',
            'message' => 'Old notice.',
        ])->assertRedirect(route('admin.settings.delivery-status'));

        $this->assertFalse(DeliveryStatus::isActive());
        $this->assertNull(DeliveryStatus::current());
    }

    public function test_update_rejects_invalid_level(): void
    {
        $this->actingAdmin();

        $this->put(route('admin.settings.delivery-status.update'), [
            'active' => '1',
            'level' => 'nope',
            'message' => 'Hi',
        ])->assertSessionHasErrors('level');
    }
}
