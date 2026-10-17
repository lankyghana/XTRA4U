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

    public function test_gwosevo_settings_are_saved_encrypted_and_independent(): void
    {
        $this->actingAdmin();
        $vendor = \App\Models\Vendor::factory()->create();

        $this->put(route('admin.settings.ussd.update'), [
            'ussd_welcome_message' => 'Welcome',
            'ussd_gwosevo_enabled' => '1',
            'ussd_gwosevo_vendor_id' => $vendor->id,
            'ussd_gwosevo_ip_allowlist' => '10.0.0.0/24',
            'ussd_gwosevo_secret' => 'a-long-gwosevo-secret-1234',
        ])->assertSessionHasNoErrors();

        $config = app(\App\Services\Ussd\UssdConfig::class);
        $this->assertTrue($config->gwoSevoEnabled());
        $this->assertSame($vendor->id, $config->gwoSevoVendorId());
        $this->assertSame(['10.0.0.0/24'], $config->gwoSevoIpAllowlist());
        $this->assertSame('a-long-gwosevo-secret-1234', $config->gwoSevoSecret());
        $this->assertNotSame('a-long-gwosevo-secret-1234', \App\Models\Setting::get('ussd_gwosevo_secret'));
        $this->assertFalse($config->hasGatewaySecret());

        // A form that omits the GWO SEVO keys must not wipe them.
        $this->put(route('admin.settings.ussd.update'), ['ussd_welcome_message' => 'Welcome'])
            ->assertSessionHasNoErrors();
        \App\Models\Setting::clearCache();
        $this->assertTrue($config->gwoSevoEnabled());
        $this->assertTrue($config->hasGwoSevoSecret());

        $this->get(route('admin.settings.ussd'))->assertOk()->assertSee('/api/ussd/gwosevo')->assertDontSee('a-long-gwosevo-secret-1234');
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

    public function test_page_shows_both_endpoints_and_no_provider_switch(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.settings.ussd'))
            ->assertOk()
            ->assertSee(route('api.ussd'), false)
            ->assertSee(route('api.ussd.gwosevo'), false)
            ->assertSee('None (refuse base-code dials)')
            ->assertDontSee('First approved vendor')
            ->assertDontSee('name="ussd_provider"', false);
    }

    public function test_page_is_organised_into_general_moolre_and_gwosevo_sections(): void
    {
        $this->actingAdmin();

        $html = $this->get(route('admin.settings.ussd'))->assertOk()->getContent();

        $general = strpos($html, 'id="ussd-general"');
        $moolre = strpos($html, 'id="ussd-moolre"');
        $gwo = strpos($html, 'id="ussd-gwosevo"');
        $this->assertNotFalse($general);
        $this->assertTrue($general < $moolre && $moolre < $gwo);

        // Each callback URL lives in its own gateway section.
        $moolreSection = substr($html, $moolre, $gwo - $moolre);
        $gwoSection = substr($html, $gwo);
        $this->assertStringContainsString(route('api.ussd'), $moolreSection);
        $this->assertStringNotContainsString(route('api.ussd.gwosevo'), $moolreSection);
        $this->assertStringContainsString(route('api.ussd.gwosevo'), $gwoSection);
        $this->assertStringContainsString('ussd_gateway_secret', $moolreSection);
        $this->assertStringNotContainsString('ussd_gwosevo_secret', $moolreSection);
        $this->assertStringContainsString('ussd_gwosevo_secret', $gwoSection);
        $this->assertStringNotContainsString('ussd_gateway_secret', $gwoSection);
    }

    public function test_saving_one_gateway_does_not_change_the_other(): void
    {
        $this->actingAdmin();
        $vendor = \App\Models\Vendor::factory()->create(['is_approved' => true]);

        $this->put(route('admin.settings.ussd.update'), [
            'ussd_welcome_message' => 'Hi',
            'ussd_gateway_ip_allowlist' => '1.1.1.1',
            'ussd_default_vendor_id' => $vendor->id,
        ])->assertSessionHasNoErrors();
        $this->put(route('admin.settings.ussd.update'), [
            'ussd_welcome_message' => 'Hi',
            'ussd_gwosevo_enabled' => '1',
            'ussd_gwosevo_ip_allowlist' => '2.2.2.2',
        ])->assertSessionHasNoErrors();
        \App\Models\Setting::clearCache();

        $config = app(\App\Services\Ussd\UssdConfig::class);
        $this->assertSame(['1.1.1.1'], $config->gatewayIpAllowlist());
        $this->assertSame($vendor->id, $config->defaultVendorId());
        $this->assertSame(['2.2.2.2'], $config->gwoSevoIpAllowlist());
        $this->assertNull($config->gwoSevoVendorId());
    }

    public function test_each_gateway_rejects_the_others_request_format(): void
    {
        \App\Models\Setting::set('ussd_enabled', '1', 'ussd');
        \App\Models\Setting::set('ussd_gwosevo_enabled', '1', 'ussd');
        \App\Models\Setting::set('ussd_gwosevo_ip_allowlist', '127.0.0.1', 'ussd');
        \App\Models\Setting::clearCache();

        $gwoPayload = ['sessionID' => 'abc123', 'ussdServiceOp' => 1, 'msisdn' => '233244123456', 'network' => '01', 'ussdString' => ''];
        $moolrePayload = ['sessionId' => 'abcdef123', 'phoneNumber' => '0244123456', 'network' => 'MTN', 'serviceCode' => '*203#', 'text' => ''];

        // A GWO SEVO payload on the Moolre endpoint fails in Moolre's plain-text protocol.
        $this->post(route('api.ussd'), $gwoPayload)->assertOk()->assertSee('END Invalid request', false);

        // A Moolre payload on the GWO SEVO endpoint fails in GWO SEVO's JSON protocol.
        $this->postJson(route('api.ussd.gwosevo'), $moolrePayload)
            ->assertOk()
            ->assertJson(['ussdServiceOp' => 17]);
    }

    public function test_stored_provider_survives_a_save_that_omits_it(): void
    {
        $this->actingAdmin();
        \App\Models\Setting::set('ussd_provider', 'moolre', 'ussd');
        \App\Models\Setting::set('ussd_base_code', '*203*', 'ussd');

        $this->put(route('admin.settings.ussd.update'), ['ussd_welcome_message' => 'Hi'])->assertSessionHasNoErrors();
        \App\Models\Setting::clearCache();

        $this->assertSame('moolre', \App\Models\Setting::get('ussd_provider'));
        $this->assertSame('*203*', \App\Models\Setting::get('ussd_base_code'));
    }

    public function test_saving_does_not_overwrite_either_stored_secret(): void
    {
        $this->actingAdmin();
        $this->put(route('admin.settings.ussd.update'), [
            'ussd_welcome_message' => 'Hi',
            'ussd_gateway_secret' => 'legacy-secret-0123456789',
            'ussd_gwosevo_secret' => 'gwosevo-secret-0123456789',
        ])->assertSessionHasNoErrors();

        $this->put(route('admin.settings.ussd.update'), [
            'ussd_welcome_message' => 'Changed',
            'ussd_gateway_secret' => '',
            'ussd_gwosevo_secret' => '',
        ])->assertSessionHasNoErrors();
        \App\Models\Setting::clearCache();

        $config = app(\App\Services\Ussd\UssdConfig::class);
        $this->assertSame('legacy-secret-0123456789', $config->gatewaySecret());
        $this->assertSame('gwosevo-secret-0123456789', $config->gwoSevoSecret());

        $this->get(route('admin.settings.ussd'))
            ->assertOk()
            ->assertDontSee('legacy-secret-0123456789')
            ->assertDontSee('gwosevo-secret-0123456789');
    }

    public function test_status_panel_distinguishes_master_legacy_and_gwosevo(): void
    {
        $this->actingAdmin();
        \App\Models\Setting::set('ussd_enabled', '0', 'ussd');
        \App\Models\Setting::set('ussd_gwosevo_enabled', '1', 'ussd');

        $this->get(route('admin.settings.ussd'))
            ->assertOk()
            ->assertSee('Disabled (master off)')
            ->assertSee('Unprotected')
            ->assertSee('Configuration incomplete')
            ->assertSee('Blocked by master')
            ->assertDontSee('Configuration ready');
    }

    public function test_gwosevo_is_not_ready_without_auth_or_an_approved_vendor(): void
    {
        $this->actingAdmin();
        $approved = \App\Models\Vendor::factory()->create(['is_approved' => true]);
        $pending = \App\Models\Vendor::factory()->create(['is_approved' => false]);

        // Vendor only: no authentication.
        \App\Models\Setting::set('ussd_gwosevo_vendor_id', (string) $approved->id, 'ussd');
        $this->get(route('admin.settings.ussd'))->assertSee('Configuration incomplete')->assertSee('an IP allowlist or a shared secret');

        // Authentication only: no vendor.
        \App\Models\Setting::set('ussd_gwosevo_vendor_id', '', 'ussd');
        \App\Models\Setting::set('ussd_gwosevo_ip_allowlist', '10.0.0.1', 'ussd');
        $this->get(route('admin.settings.ussd'))->assertSee('Configuration incomplete')->assertSee('a vendor to serve');

        // An unapproved vendor does not count.
        \App\Models\Setting::set('ussd_gwosevo_vendor_id', (string) $pending->id, 'ussd');
        $this->get(route('admin.settings.ussd'))->assertSee('Configuration incomplete')->assertSee('the selected one is not approved');

        // Both present.
        \App\Models\Setting::set('ussd_gwosevo_vendor_id', (string) $approved->id, 'ussd');
        $this->get(route('admin.settings.ussd'))->assertSee('Configuration ready')->assertDontSee('Configuration incomplete');
    }
}
