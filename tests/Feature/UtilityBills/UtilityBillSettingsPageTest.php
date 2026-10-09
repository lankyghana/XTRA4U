<?php

namespace Tests\Feature\UtilityBills;

use App\Models\User;
use App\Models\UtilityBillConfigAudit;
use App\Models\UtilityBillerConfig;
use App\Services\UtilityBills\UtilityBillSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Rendering/regression tests for the redesigned admin Utility Bills settings page. UI only; HTTP is always faked. */
class UtilityBillSettingsPageTest extends UtilityBillTestCase
{
    private function page(): \Illuminate\Testing\TestResponse
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        return $this->get(route('admin.utility-bills.settings'))->assertOk();
    }

    public function test_no_blade_syntax_is_rendered_as_text_in_any_state(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $states = [
            'env key' => fn () => null,
            'admin key' => function () {
                \App\Services\UtilityBills\UtilityBillCredentials::save('kf_cs_live_adminsavedkey9876', ['id' => null, 'email' => 'a@x.com', 'ip' => null]);
            },
            'no key' => function () {
                \App\Services\UtilityBills\UtilityBillCredentials::save(null, ['id' => null, 'email' => 'a@x.com', 'ip' => null]);
                config(['services.kingflexy_utilities.api_key' => '']);
            },
        ];

        foreach ($states as $name => $arrange) {
            $arrange();
            $html = $this->page()->getContent();
            foreach (['\\csrf', '\\method', '\\if', '\\else', '\\endif', '@csrf', '@method', '@if', '@endif', '@foreach', '@error'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$name}: found '{$needle}'");
            }
        }
    }

    public function test_every_admin_utility_bills_view_is_free_of_escaped_directives(): void
    {
        foreach (glob(resource_path('views/admin/utility_bills/*.blade.php')) as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*\\\\(if|else|elseif|endif|csrf|method|foreach|endforeach|forelse|empty|endforelse|php|endphp|error|enderror)\b/m',
                file_get_contents($file),
                basename($file).' contains a backslash-escaped Blade directive'
            );
        }
    }

    public function test_missing_key_shows_not_configured_state_and_never_a_secret(): void
    {
        config(['services.kingflexy_utilities.api_key' => '']);
        Http::fake();

        $this->page()
            ->assertSee('Not connected')
            ->assertSee('Not configured')
            ->assertSee('No billers loaded')
            ->assertSee('Configure API key')
            ->assertDontSee('kf_cs_live_');
        Http::assertNothingSent();
    }

    public function test_rejected_key_is_distinct_from_missing_key(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response(['success' => false], 401)]);

        $this->page()
            ->assertSee('Invalid API key')
            ->assertSee('Rejected by provider')
            ->assertSee('Unable to connect to KiNG FLEXY')
            ->assertDontSee('No billers loaded')
            ->assertDontSee('kf_cs_live_');
    }

    public function test_unreachable_provider_has_its_own_message(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => fn () => throw new ConnectionException('down')]);

        $this->page()
            ->assertSee('Connection unavailable')
            ->assertSee('Provider temporarily unavailable')
            ->assertSee('Your saved configuration is unchanged')
            ->assertDontSee('Invalid API key');
    }

    public function test_env_and_admin_key_sources_are_labelled_without_showing_the_key(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        $this->page()->assertSee('Connected')->assertSee('Configured from environment')->assertSee('Change API key')
            ->assertDontSee('Remove override')->assertDontSee('kf_cs_live_testkey123');

        $key = 'kf_cs_live_adminsavedkey9876';
        \App\Services\UtilityBills\UtilityBillCredentials::save($key, ['id' => null, 'email' => 'a@x.com', 'ip' => null]);
        $this->page()->assertSee('Configured in Admin')->assertSee('Remove override and use environment key')
            ->assertSee('9876')->assertDontSee($key, false);
    }

    public function test_service_state_and_toggle_render_for_on_and_off(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        UtilityBillSettings::save(true, null);
        $this->page()->assertSee('Active')->assertSee('Utility Bills are accepting new orders.')
            ->assertSee('role="switch"', false)->assertSee('name="enabled"', false);

        UtilityBillSettings::save(false, 'Back soon');
        $this->page()->assertSee('Disabled')->assertSee('New Utility Bill orders are paused.')->assertSee('Back soon');
    }

    public function test_biller_cards_show_provider_and_xtra4u_status_and_commission_controls(): void
    {
        $body = $this->billersBody();
        $body['data']['billers'][1]['enabled'] = false; // Ghana Water unavailable at provider
        $this->fake([self::BASE.'/utilities/billers' => Http::response($body)]);
        UtilityBillSettings::save(true, null);
        UtilityBillerConfig::create(['biller_key' => 'ecg', 'is_enabled' => true, 'commission_type' => 'percentage', 'commission_value' => '0.5']);
        UtilityBillerConfig::create(['biller_key' => 'dstv', 'is_enabled' => true, 'commission_type' => 'fixed', 'commission_value' => '1']);

        $page = $this->page();
        foreach (['ECG Prepaid', 'Ghana Water', 'DSTV', 'GOtv', 'StarTimes'] as $label) {
            $page->assertSee($label);
        }
        $page->assertSee('Available')->assertSee('Unavailable')
            ->assertSee('XTRA4U sales')
            ->assertSee('Provider has temporarily disabled this biller.')
            ->assertSee('Allowed amount')->assertSee('GHS 1.00 – GHS 1,000.00')
            ->assertSee('No completed-sale data yet')
            ->assertSee('>● Selling now</p>', false)->assertSee('Not selling: provider unavailable')
            ->assertSee('Not selling: XTRA4U sales are off')
            ->assertSee('Cannot be sold until the provider enables it')
            ->assertSee('billers[ecg][commission_type]', false)->assertSee('billers[dstv][commission_value]', false)
            ->assertSee('value="0.5"', false)->assertSee('value="1"', false)
            ->assertSee('Learn about commissions')->assertSee('Changes apply only to new orders');
    }

    public function test_master_switch_off_means_nothing_is_selling_and_no_pricing_controls_exist(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        UtilityBillSettings::save(false, null);
        UtilityBillerConfig::create(['biller_key' => 'ecg', 'is_enabled' => true, 'commission_type' => 'percentage', 'commission_value' => '0.5']);

        $this->page()->assertSee('Not selling: Utility Bills is off')->assertDontSee('>● Selling now</p>', false)
            // Admin never edits prices, fees, markup, provider commission or limits.
            ->assertDontSee('name="markup', false)->assertDontSee('name="fee', false)->assertDontSee('name="price', false)
            ->assertDontSee('name="min_amount', false)->assertDontSee('name="max_amount', false)
            ->assertDontSee('name="provider_commission', false)->assertDontSee('name="label', false);
    }

    public function test_commission_warning_is_contextual_and_not_blocking(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        UtilityBillerConfig::create(['biller_key' => 'dstv', 'is_enabled' => true, 'commission_type' => 'percentage', 'commission_value' => '1']);
        $this->page()->assertDontSee('may exceed recent provider commission');

        $u = $this->makeOrder(['paid' => true, 'amount' => '100.00', 'biller' => 'dstv']);
        $u->forceFill(['fulfillment_status' => \App\Services\UtilityBills\FulfillmentStatus::COMPLETED, 'provider_commission_earned' => '0.40'])->save();

        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->page()->assertSee('Vendor commission may exceed recent provider commission')->assertSee('0.40%');
    }

    public function test_history_is_readable_and_raw_json_is_only_in_details(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->put(route('admin.utility-bills.settings.update'), ['enabled' => 1, 'maintenance_message' => '', 'billers' => ['ecg' => ['is_enabled' => 1, 'commission_type' => 'percentage', 'commission_value' => '0.5']]])->assertRedirect();
        $this->put(route('admin.utility-bills.settings.update'), ['enabled' => 1, 'maintenance_message' => '', 'billers' => ['ecg' => ['is_enabled' => 1, 'commission_type' => 'percentage', 'commission_value' => '0.75']]])->assertRedirect();

        $html = $this->get(route('admin.utility-bills.settings'))->assertOk()
            ->assertSee('Utility Bills enabled')
            ->assertSee('ECG Prepaid &amp; Postpaid updated', false)
            ->assertSee('Vendor commission: 0.5% → 0.75%')
            ->assertSee('Status: Disabled → Enabled')
            ->getContent();

        // Compact one-line JSON dumps (the old debugging view) are gone from the visible list.
        $this->assertStringNotContainsString('{"enabled":false', $html);
        $this->assertSame(UtilityBillConfigAudit::count() > 0, true);
    }

    public function test_authorization_still_applies(): void
    {
        $this->get(route('admin.utility-bills.settings'))->assertRedirect(route('admin.login'));
    }
}
