<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticAndVendorAuthPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_renders_with_the_redesigned_chrome(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('About Us')
            ->assertSee('Become a Vendor')
            ->assertSee('x4-btn', false);
    }

    public function test_privacy_page_renders_with_the_redesigned_chrome(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Information We Collect')
            ->assertSee('x4-btn', false);
    }

    public function test_terms_page_renders_with_the_redesigned_chrome(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Reseller &amp; Vendor Accounts', false)
            ->assertSee('x4-btn', false);
    }

    public function test_vendor_login_page_renders_and_posts_to_the_correct_route(): void
    {
        $this->get(route('vendor.login.form'))
            ->assertOk()
            ->assertSee('Vendor Portal')
            ->assertSee('action="' . route('vendor.login') . '"', false)
            ->assertSee('id="toggle_password_visibility"', false)
            ->assertSee('id="icon_eye"', false)
            ->assertSee('id="icon_eye_off"', false);
    }

    public function test_vendor_login_shows_whatsapp_support_link_for_unapproved_vendor(): void
    {
        Vendor::factory()->create([
            'email' => 'pending@example.com',
            'password' => bcrypt('secret123'),
            'is_approved' => false,
        ]);
        Setting::set('vendor_approval_contact_number', '0244000000');

        $response = $this->from(route('vendor.login.form'))->post(route('vendor.login'), [
            'email' => 'pending@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('vendor.login.form'));

        $follow = $this->get(route('vendor.login.form'));
        $follow->assertOk();
        $follow->assertSee('has not been approved yet', false);
        $follow->assertSee('Chat on WhatsApp');
    }

    public function test_vendor_forgot_password_page_renders_and_posts_to_the_correct_route(): void
    {
        $this->get(route('vendor.password.forgot'))
            ->assertOk()
            ->assertSee('Forgot Password?')
            ->assertSee('action="' . route('vendor.password.email') . '"', false);
    }

    public function test_vendor_request_page_renders_and_posts_to_the_correct_route(): void
    {
        $response = $this->get(route('vendor.request.form'));

        $response->assertOk();
        $response->assertSee('Become a Vendor');
        $response->assertSee('action="' . route('vendor.request.submit') . '"', false);
        // Every field the controller validates must still be present.
        foreach (['name="name"', 'name="email"', 'name="phone_number"', 'name="password"', 'name="affiliate_vendor_code"'] as $field) {
            $response->assertSee($field, false);
        }
        // The login link must point at the login form, not the POST-only route.
        $response->assertSee(route('vendor.login.form'), false);
    }
}
