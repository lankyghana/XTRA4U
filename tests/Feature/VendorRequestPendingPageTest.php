<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorRequestPendingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_page_renders_with_the_vendor_name_and_redesigned_chrome(): void
    {
        $response = $this->get(route('vendor.request.pending', ['name' => 'University of Ghana']));

        $response->assertOk();
        $response->assertSee('Request Submitted!');
        $response->assertSee('Thanks, University of Ghana', false);
        $response->assertSee('x4-btn', false);
        $response->assertSee(route('vendor.login.form'), false);
        $response->assertSee(route('storefront.index'), false);
    }

    public function test_pending_page_shows_whatsapp_contact_when_configured(): void
    {
        Setting::set('vendor_approval_contact_number', '0244000000');

        $response = $this->get(route('vendor.request.pending', ['name' => 'Ama Data Hub']));

        $response->assertOk();
        $response->assertSee('0244000000');
        $response->assertSee('Chat on WhatsApp');
    }

    public function test_pending_page_falls_back_to_generic_message_without_a_name(): void
    {
        $response = $this->get(route('vendor.request.pending'));

        $response->assertOk();
        $response->assertSee('Your vendor registration request has been received.');
    }
}
