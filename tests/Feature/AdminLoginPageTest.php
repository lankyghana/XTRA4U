<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_renders_with_the_redesigned_chrome(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk();
        $response->assertSee('Admin Portal');
        $response->assertSee('Secure administrator access only');
        $response->assertSee('action="' . route('admin.login.submit') . '"', false);
        $response->assertSee('x4-btn', false);
    }

    public function test_valid_credentials_still_log_the_admin_in(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->post(route('admin.login.submit'), [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_invalid_credentials_show_a_validation_error_on_the_redesigned_page(): void
    {
        Admin::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->from(route('admin.login'))->post(route('admin.login.submit'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('admin.login'));
        $response->assertSessionHasErrors('email');

        $follow = $this->get(route('admin.login'));
        $follow->assertOk();
        $follow->assertSee(__('auth.failed'));
    }
}
