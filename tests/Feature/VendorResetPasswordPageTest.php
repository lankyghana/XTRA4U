<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorResetPasswordPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_page_renders_with_the_redesigned_chrome(): void
    {
        $response = $this->get(route('vendor.password.reset', ['token' => 'abc123']) . '?email=vendor@example.com');

        $response->assertOk();
        $response->assertSee('Reset Password');
        $response->assertSee('value="abc123"', false);
        $response->assertSee('value="vendor@example.com"', false);
        $response->assertSee('action="' . route('vendor.password.update') . '"', false);
        $response->assertSee('x4-btn', false);
    }
}
