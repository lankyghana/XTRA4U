<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUssdSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        return $admin;
    }

    public function test_ussd_settings_page_loads(): void
    {
        $this->actingAdmin();
        $response = $this->get(route('admin.settings.ussd'));
        $response->assertStatus(200);
        $response->assertSee('USSD Settings');
    }

    public function test_ussd_settings_can_be_updated(): void
    {
        $this->actingAdmin();
        $response = $this->put(route('admin.settings.ussd.update'), [
            'ussd_welcome_message' => 'Welcome to Test',
            'ussd_service_code' => '*714*5#',
            'ussd_support_number' => '0244000000',
        ]);
        $response->assertRedirect(route('admin.settings.ussd'));
        $response->assertSessionHas('success');
    }
}
