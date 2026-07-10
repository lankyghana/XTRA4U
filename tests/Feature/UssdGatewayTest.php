<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\UssdPlan;
use App\Models\UssdSession;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class UssdGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private UssdSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->subscription = $this->subscribe($this->vendor, $this->starter());
    }

    private function starter(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    private function subscribe(Vendor $vendor, UssdPlan $plan, array $overrides = []): UssdSubscription
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

    private function setting(string $key, string $value): void
    {
        Setting::set($key, $value, 'ussd');
        Setting::clearCache();
    }

    /** The dialled suffix for this vendor's code: "45*<vendor id>". */
    private function dial(string $suffix = ''): string
    {
        $prefix = "45*{$this->vendor->id}";

        return $suffix === '' ? $prefix : "{$prefix}*{$suffix}";
    }

    private function ussd(array $payload = [], array $headers = [])
    {
        return $this->post('/api/ussd', array_merge([
            'sessionId' => 'sess-abc-123456',
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => $this->dial(),
        ], $payload), $headers);
    }

    // -------------------------------------------------------------- happy path

    public function test_dialling_a_subscribed_vendor_shows_the_main_menu(): void
    {
        $response = $this->ussd();

        $response->assertOk();
        $this->assertStringStartsWith('CON ', $response->getContent());
        $response->assertSee('Buy Data Bundle', false);
        $response->assertSee('Results Checker', false);
    }

    public function test_opening_a_session_reserves_exactly_one_paid_session(): void
    {
        $this->ussd();

        $this->assertSame(1, $this->subscription->fresh()->used_sessions);

        $session = UssdSession::firstOrFail();
        $this->assertSame($this->vendor->id, $session->vendor_id);
        $this->assertSame($this->subscription->id, $session->ussd_subscription_id);
        $this->assertSame(2, $session->getData('prefix_length'));
        $this->assertSame(UssdSession::STATUS_ACTIVE, $session->status);
    }

    public function test_continuing_a_session_does_not_reserve_another(): void
    {
        $this->ussd();
        $response = $this->ussd(['text' => $this->dial('1')]); // press 1: Buy Data Bundle

        $response->assertSee('Select Network', false);
        $this->assertSame(1, $this->subscription->fresh()->used_sessions, 'Only the dial reserves a session.');
    }

    public function test_non_cumulative_gateways_are_understood(): void
    {
        $this->ussd();

        // Some aggregators send only the latest keypress rather than the full string.
        $this->ussd(['text' => '1'])->assertSee('Select Network', false);
    }

    public function test_first_request_never_treats_the_dialled_code_as_a_keypress(): void
    {
        // Regression: the old code read end(explode('*', $text)) as the keypress,
        // so the vendor segment landed on the main menu as "Invalid choice".
        $this->ussd()->assertDontSee('Invalid choice', false);
    }

    // ------------------------------------------------------- subscription gate

    public function test_vendor_without_a_subscription_is_refused(): void
    {
        $other = Vendor::factory()->create();

        $this->ussd(['text' => "45*{$other->id}"])
            ->assertSee('not currently active for this vendor', false);

        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    public function test_expired_subscription_is_refused(): void
    {
        $this->subscription->forceFill(['expires_at' => now()->subDay()])->save();

        $this->ussd()->assertSee('not currently active for this vendor', false);
        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
    }

    public function test_exhausted_subscription_is_refused(): void
    {
        $this->subscription->forceFill(['used_sessions' => 5000])->save();

        $this->ussd()->assertSee('temporarily unavailable', false);
        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    public function test_stale_extension_code_is_refused(): void
    {
        // Vendor moved to the Business plan; their old *203*45*<id># is dead.
        $this->subscription->forceFill(['extension_code' => '46'])->save();

        $this->ussd()->assertSee('no longer valid', false);
    }

    public function test_unknown_vendor_id_is_refused_and_never_falls_back(): void
    {
        $this->ussd(['text' => '45*999999'])->assertSee('Invalid code', false);
        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    public function test_base_code_alone_reaches_the_configured_default_vendor(): void
    {
        $this->setting('ussd_default_vendor_id', (string) $this->vendor->id);

        $this->ussd(['text' => ''])->assertSee('Buy Data Bundle', false);
        $this->assertSame(0, UssdSession::firstOrFail()->getData('prefix_length'));
    }

    public function test_base_code_alone_is_refused_when_no_default_vendor_is_set(): void
    {
        $this->setting('ussd_default_vendor_id', '');

        $this->ussd(['text' => ''])->assertSee('Invalid code', false);
    }

    public function test_legacy_vendor_code_routing_still_works(): void
    {
        $this->ussd(['text' => $this->vendor->vendor_code])
            ->assertSee('Buy Data Bundle', false);

        $this->assertSame(1, UssdSession::firstOrFail()->getData('prefix_length'));
    }

    public function test_refused_requests_are_audited(): void
    {
        $this->ussd(['text' => '45*999999']);

        $this->assertDatabaseHas('ussd_subscription_events', [
            'event' => UssdSubscriptionEvent::INVALID_USSD_REQUEST,
        ]);
    }

    // -------------------------------------------------------- session security

    public function test_an_ended_session_cannot_be_replayed(): void
    {
        $this->ussd();
        UssdSession::firstOrFail()->forceFill([
            'status' => UssdSession::STATUS_ENDED,
            'ended_at' => now(),
        ])->save();

        $this->ussd(['text' => $this->dial('1')])
            ->assertSee('already ended', false);
    }

    public function test_ending_a_session_retains_the_row(): void
    {
        $this->ussd();

        // Drive to a terminating branch by exhausting retries on the main menu.
        $this->setting('ussd_max_retry_attempts', '1');
        $this->ussd(['text' => $this->dial('9')]);

        $session = UssdSession::firstOrFail();
        $this->assertSame(UssdSession::STATUS_ENDED, $session->status);
        $this->assertNotNull($session->ended_at);
    }

    public function test_a_timed_out_session_cannot_continue(): void
    {
        $this->setting('ussd_session_timeout_seconds', '30');
        $this->ussd();

        UssdSession::firstOrFail()->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->ussd(['text' => $this->dial('1')])->assertSee('timed out', false);
        $this->assertSame(UssdSession::STATUS_EXPIRED, UssdSession::firstOrFail()->status);
    }

    public function test_request_cap_ends_the_session(): void
    {
        $this->setting('ussd_max_requests_per_session', '3');
        $this->ussd();

        $this->ussd(['text' => $this->dial('1')]);
        $this->ussd(['text' => $this->dial('1*9')]);
        $this->ussd(['text' => $this->dial('1*9*9')])->assertSee('Too many requests', false);

        $this->assertSame(UssdSession::STATUS_ENDED, UssdSession::firstOrFail()->status);
    }

    public function test_retry_cap_ends_the_session(): void
    {
        $this->setting('ussd_max_retry_attempts', '2');
        $this->ussd();

        // First invalid entry re-prompts.
        $this->ussd(['text' => $this->dial('9')])->assertSee('Invalid choice', false);

        // Second exceeds the cap.
        $this->ussd(['text' => $this->dial('9*9')])->assertSee('Too many invalid entries', false);
    }

    public function test_subscription_exhausted_mid_call_stops_the_session(): void
    {
        $this->ussd();
        $this->subscription->forceFill(['used_sessions' => 5000])->save();

        $this->ussd(['text' => $this->dial('1')])->assertSee('temporarily unavailable', false);
    }

    // ------------------------------------------------------------- kill switch

    public function test_disabled_channel_returns_unavailable(): void
    {
        $this->setting('ussd_enabled', '0');

        $this->ussd()->assertSee('temporarily unavailable', false);
        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    // --------------------------------------------------------------- validation

    public function test_missing_session_id_is_rejected_in_protocol(): void
    {
        $response = $this->ussd(['sessionId' => '']);

        $response->assertOk();
        $this->assertSame('END Invalid request. Please dial again.', $response->getContent());
    }

    public function test_malicious_text_is_rejected(): void
    {
        $this->ussd(['text' => "45*1'; DROP TABLE vendors;--"])
            ->assertSee('Invalid request', false);
    }

    public function test_bad_phone_number_is_rejected(): void
    {
        $this->ussd(['phoneNumber' => 'not-a-number'])->assertSee('Invalid request', false);
    }

    // ------------------------------------------------------- gateway auth

    public function test_ip_allowlist_blocks_unlisted_sources(): void
    {
        $this->setting('ussd_gateway_ip_allowlist', '203.0.113.5, 198.51.100.0/24');

        $this->ussd()->assertSee('not available from your connection', false);
        $this->assertDatabaseCount('ussd_sessions', 0);

        $this->assertDatabaseHas('ussd_subscription_events', [
            'event' => UssdSubscriptionEvent::SECURITY_EVENT,
        ]);
    }

    public function test_ip_allowlist_admits_listed_sources(): void
    {
        // The test client presents 127.0.0.1.
        $this->setting('ussd_gateway_ip_allowlist', '127.0.0.0/8');

        $this->ussd()->assertSee('Buy Data Bundle', false);
    }

    public function test_shared_secret_is_required_once_configured(): void
    {
        $this->setting('ussd_gateway_secret', Crypt::encryptString('a-very-long-gateway-secret'));

        $this->ussd()->assertSee('not available from your connection', false);
    }

    public function test_correct_shared_secret_is_admitted(): void
    {
        $this->setting('ussd_gateway_secret', Crypt::encryptString('a-very-long-gateway-secret'));

        $this->ussd([], ['X-Ussd-Secret' => 'a-very-long-gateway-secret'])
            ->assertSee('Buy Data Bundle', false);
    }

    public function test_wrong_shared_secret_is_rejected(): void
    {
        $this->setting('ussd_gateway_secret', Crypt::encryptString('a-very-long-gateway-secret'));

        $this->ussd([], ['X-Ussd-Secret' => 'wrong-secret-value'])
            ->assertSee('not available from your connection', false);
    }

    public function test_bearer_prefixed_authorization_header_is_accepted(): void
    {
        $this->setting('ussd_gateway_secret', Crypt::encryptString('a-very-long-gateway-secret'));

        $this->ussd([], ['Authorization' => 'Bearer a-very-long-gateway-secret'])
            ->assertSee('Buy Data Bundle', false);
    }

    public function test_no_gateway_controls_configured_means_open_access(): void
    {
        // Default state, preserved for backward compatibility.
        $this->ussd()->assertSee('Buy Data Bundle', false);
    }
}
