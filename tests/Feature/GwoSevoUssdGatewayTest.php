<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Setting;
use App\Models\UssdLog;
use App\Models\UssdPlan;
use App\Models\UssdSession;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use App\Services\Ussd\GwoSevo\GwoSevoProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GwoSevoUssdGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'gwosevo-shared-secret-0123456789';

    private Vendor $vendor;

    private UssdSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->subscription = $this->subscribe($this->vendor);

        $this->setting('ussd_gwosevo_enabled', '1');
        $this->setting('ussd_gwosevo_vendor_id', (string) $this->vendor->id);
        $this->setting('ussd_gwosevo_secret', Crypt::encryptString(self::SECRET));
    }

    private function subscribe(Vendor $vendor, array $overrides = []): UssdSubscription
    {
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        return UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'ussd_code' => UssdSubscription::buildUssdCode('*203*', '45', $vendor->id),
            'extension_code' => '45',
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

    private function gwo(array $payload = [], ?array $headers = null)
    {
        return $this->postJson('/api/ussd/gwosevo', array_merge([
            'sessionID' => '12345',
            'ussdServiceOp' => 1,
            'ussdString' => '',
            'network' => '01',
            'msisdn' => '233531005130',
        ], $payload), $headers ?? ['X-Ussd-Secret' => self::SECRET]);
    }

    private function press(string $input, array $payload = [])
    {
        return $this->gwo(array_merge(['ussdServiceOp' => 18, 'ussdString' => $input], $payload));
    }

    // -------------------------------------------------------------- happy path

    public function test_new_session_returns_the_main_menu_in_gwo_json(): void
    {
        $this->gwo()
            ->assertOk()
            ->assertJson(['ussdServiceOp' => 2, 'sessionID' => '12345'])
            ->assertJsonStructure(['message', 'ussdServiceOp', 'sessionID'])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Buy Data Bundle') && ! str_starts_with($m, 'CON'));

        $session = UssdSession::firstOrFail();
        $this->assertStringStartsWith('gwosevo:', $session->session_id);
        $this->assertSame($this->vendor->id, $session->vendor_id);
        $this->assertSame('MTN', $session->network);
        $this->assertSame('0531005130', $session->phone_number);
        $this->assertSame(1, $this->subscription->fresh()->used_sessions);
    }

    public function test_session_continuation_navigates_the_shared_menu_without_a_new_reservation(): void
    {
        $this->gwo();

        $this->press('1')
            ->assertJson(['ussdServiceOp' => 2, 'sessionID' => '12345'])
            ->assertSee('Select Network');

        $this->assertSame(1, $this->subscription->fresh()->used_sessions);
    }

    public function test_session_termination_uses_operation_17_and_closes_the_session(): void
    {
        $this->gwo();
        // Exhaust the retry cap with invalid input so the shared menu ends the call.
        $this->setting('ussd_max_retry_attempts', '1');

        $response = $this->press('9');

        $response->assertJson(['ussdServiceOp' => 17, 'sessionID' => '12345']);
        $this->assertStringNotContainsString('END', $response->json('message'));
        $this->assertSame(UssdSession::STATUS_ENDED, UssdSession::firstOrFail()->status);

        $this->press('1')->assertJson(['ussdServiceOp' => 17])->assertSee('already ended');
    }

    public function test_invalid_customer_input_continues_the_session(): void
    {
        $this->gwo();

        $this->press('9')
            ->assertJson(['ussdServiceOp' => 2, 'sessionID' => '12345'])
            ->assertSee('Invalid choice');
    }

    public function test_gateway_session_id_is_echoed_exactly_and_never_leaks_the_internal_key(): void
    {
        $response = $this->gwo(['sessionID' => 'ab-9']);

        $response->assertJson(['sessionID' => 'ab-9']);
        $this->assertStringNotContainsString('gwosevo:', $response->getContent());
    }

    // ------------------------------------------------------------ validation

    public function test_invalid_operation_ends_the_session(): void
    {
        $this->gwo(['ussdServiceOp' => 5])
            ->assertOk()
            ->assertJson(['ussdServiceOp' => 17, 'sessionID' => '12345']);

        $this->assertDatabaseCount('ussd_sessions', 0);
        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
    }

    public function test_malformed_payloads_end_safely_in_protocol(): void
    {
        foreach ([
            ['msisdn' => 'not-a-number'],
            ['network' => '03'],
            ['ussdString' => str_repeat('1', 300)],
            ['ussdString' => '<script>'],
            ['sessionID' => 'bad id with spaces'],
            ['sessionID' => str_repeat('a', 65)],
        ] as $bad) {
            $response = $this->gwo($bad)->assertOk()->assertJson(['ussdServiceOp' => 17]);
            $this->assertContains($response->json('sessionID'), ['12345', ''], 'Unsafe ids must not be echoed.');
        }

        $this->postJson('/api/ussd/gwosevo', [], ['X-Ussd-Secret' => self::SECRET])
            ->assertOk()->assertJson(['ussdServiceOp' => 17, 'sessionID' => '']);

        $this->assertDatabaseCount('ussd_sessions', 0);
        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
    }

    public function test_form_encoded_requests_are_supported(): void
    {
        $this->post('/api/ussd/gwosevo', [
            'sessionID' => '777777',
            'ussdServiceOp' => '1',
            'ussdString' => '',
            'network' => '02',
            'msisdn' => '233241234567',
        ], ['X-Ussd-Secret' => self::SECRET])
            ->assertOk()
            ->assertJson(['ussdServiceOp' => 2, 'sessionID' => '777777']);

        $this->assertSame('Telecel', UssdSession::firstOrFail()->network);
    }

    public function test_numeric_json_values_are_accepted(): void
    {
        $this->gwo(['sessionID' => 12345, 'network' => 1, 'msisdn' => 233531005130])
            ->assertJson(['ussdServiceOp' => 2, 'sessionID' => '12345']);
    }

    public function test_continuing_an_unknown_session_ends_without_reserving(): void
    {
        $this->press('1', ['sessionID' => 'never-started'])
            ->assertJson(['ussdServiceOp' => 17, 'sessionID' => 'never-started']);

        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
    }

    // ------------------------------------------------------ network / msisdn

    public function test_network_codes_map_per_the_guide(): void
    {
        $this->assertSame('MTN', GwoSevoProtocol::networkName('01'));
        $this->assertSame('Telecel', GwoSevoProtocol::networkName('02'));
        $this->assertSame('AT', GwoSevoProtocol::networkName('04'));
        $this->assertNull(GwoSevoProtocol::networkName('03'));

        foreach (['01' => 'MTN', '02' => 'Telecel', '04' => 'AT'] as $code => $name) {
            UssdSession::query()->delete();
            $this->gwo(['network' => $code, 'sessionID' => "s{$code}"]);
            $this->assertSame($name, UssdSession::firstOrFail()->network);
        }
    }

    public function test_msisdn_normalisation(): void
    {
        $this->assertSame('0531005130', GwoSevoProtocol::localMsisdn('233531005130'));
        $this->assertSame('0531005130', GwoSevoProtocol::localMsisdn('+233531005130'));
        $this->assertSame('0531005130', GwoSevoProtocol::localMsisdn('0531005130'));
        $this->assertSame('0531005130', GwoSevoProtocol::localMsisdn('531005130'));
        $this->assertNull(GwoSevoProtocol::localMsisdn('12345678901234'));
        $this->assertNull(GwoSevoProtocol::localMsisdn('abc'));
    }

    // -------------------------------------------------- session id namespacing

    public function test_repeated_start_does_not_consume_another_paid_session(): void
    {
        $this->gwo()->assertJson(['ussdServiceOp' => 2]);
        $this->gwo()->assertJson(['ussdServiceOp' => 2, 'sessionID' => '12345']);
        $this->gwo()->assertJson(['ussdServiceOp' => 2]);

        $this->assertSame(1, $this->subscription->fresh()->used_sessions);
        $this->assertDatabaseCount('ussd_sessions', 1);
    }

    public function test_repeated_start_returns_to_the_main_menu(): void
    {
        $this->gwo();
        $this->press('1');

        $this->gwo()->assertSee('Buy Data Bundle');
    }

    public function test_same_gateway_id_from_different_subscribers_never_collides(): void
    {
        $this->gwo(['msisdn' => '233531005130']);
        $this->gwo(['msisdn' => '233241234567']);

        $this->assertDatabaseCount('ussd_sessions', 2);
        $this->assertSame(2, $this->subscription->fresh()->used_sessions);

        // A different subscriber cannot continue someone else's session.
        $this->press('1', ['msisdn' => '233200000000'])->assertJson(['ussdServiceOp' => 17]);
    }

    public function test_a_reused_gateway_id_after_the_call_ended_starts_a_fresh_generation(): void
    {
        $this->gwo();
        UssdSession::firstOrFail()->forceFill(['status' => UssdSession::STATUS_ENDED, 'ended_at' => now()])->save();

        $this->gwo()->assertJson(['ussdServiceOp' => 2, 'sessionID' => '12345']);

        $this->assertDatabaseCount('ussd_sessions', 2);
        $this->assertSame(2, $this->subscription->fresh()->used_sessions);
        $this->assertTrue(UssdSession::orderByDesc('id')->first()->isActive());
        $this->press('1')->assertSee('Select Network');
    }

    public function test_namespacing_does_not_collide_with_the_other_gateway(): void
    {
        $this->gwo();

        // The legacy gateway can never mint an id in the GWO SEVO namespace.
        $internal = UssdSession::firstOrFail()->session_id;
        $this->setting('ussd_gateway_secret', '');
        $this->post('/api/ussd', [
            'sessionId' => $internal,
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => "45*{$this->vendor->id}",
        ])->assertSee('Invalid request');

        // And it keeps working side by side.
        $this->post('/api/ussd', [
            'sessionId' => 'legacy-123456',
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => "45*{$this->vendor->id}",
        ])->assertSee('Buy Data Bundle');
    }

    // ------------------------------------------------------- vendor routing

    public function test_unconfigured_vendor_is_refused_and_never_defaulted(): void
    {
        $this->setting('ussd_gwosevo_vendor_id', '');
        $other = Vendor::factory()->create();
        $this->subscribe($other);
        $this->setting('ussd_default_vendor_id', (string) $other->id);

        $this->gwo()->assertJson(['ussdServiceOp' => 17])->assertSee('Invalid code');

        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    public function test_a_start_carrying_a_dial_string_is_refused_rather_than_guessed(): void
    {
        $other = Vendor::factory()->create();
        $this->subscribe($other);

        $this->gwo(['ussdString' => "45*{$other->id}"])
            ->assertJson(['ussdServiceOp' => 17]);

        $this->assertDatabaseCount('ussd_sessions', 0);
        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
        $this->assertSame(0, $other->currentUssdSubscription()->first()->used_sessions);
    }

    public function test_unapproved_vendor_is_refused(): void
    {
        $this->vendor->forceFill(['is_approved' => false])->save();

        $this->gwo()->assertJson(['ussdServiceOp' => 17]);
        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    // ----------------------------------------------------------- subscription

    public function test_vendor_without_subscription_is_refused(): void
    {
        $other = Vendor::factory()->create();
        $this->setting('ussd_gwosevo_vendor_id', (string) $other->id);

        $this->gwo()->assertJson(['ussdServiceOp' => 17])->assertSee('not currently active');
        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    public function test_expired_subscription_is_refused(): void
    {
        $this->subscription->forceFill(['expires_at' => now()->subDay()])->save();

        $this->gwo()->assertJson(['ussdServiceOp' => 17])->assertSee('not currently active');
        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
    }

    public function test_exhausted_subscription_is_refused(): void
    {
        $this->subscription->forceFill(['used_sessions' => 5000])->save();

        $this->gwo()->assertJson(['ussdServiceOp' => 17])->assertSee('temporarily unavailable');
        $this->assertSame(5000, $this->subscription->fresh()->used_sessions);
    }

    public function test_subscription_expiring_mid_call_ends_the_session(): void
    {
        $this->gwo();
        $this->subscription->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->press('1')->assertJson(['ussdServiceOp' => 17]);
        $this->assertSame(UssdSession::STATUS_ENDED, UssdSession::firstOrFail()->status);
    }

    public function test_gateway_disabled_or_channel_disabled_is_refused(): void
    {
        $this->setting('ussd_gwosevo_enabled', '0');
        $this->gwo()->assertJson(['ussdServiceOp' => 17, 'sessionID' => '12345']);

        $this->setting('ussd_gwosevo_enabled', '1');
        $this->setting('ussd_enabled', '0');
        $this->gwo()->assertJson(['ussdServiceOp' => 17]);

        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    // -------------------------------------------------------------- security

    public function test_requests_without_the_secret_are_rejected_and_audited(): void
    {
        foreach ([[], ['X-Ussd-Secret' => 'wrong-secret-wrong-secret']] as $headers) {
            $this->gwo([], $headers)
                ->assertOk()
                ->assertJson(['ussdServiceOp' => 17, 'sessionID' => '']);
        }

        $this->assertDatabaseCount('ussd_sessions', 0);
        $this->assertSame(0, $this->subscription->fresh()->used_sessions);
        $this->assertSame(2, UssdSubscriptionEvent::where('event', UssdSubscriptionEvent::SECURITY_EVENT)->count());
    }

    public function test_secret_is_accepted_as_bearer_api_key_or_query_parameter(): void
    {
        foreach ([
            ['Authorization' => 'Bearer '.self::SECRET],
            ['X-Api-Key' => self::SECRET],
        ] as $i => $headers) {
            $this->gwo(['sessionID' => "s{$i}"], $headers)->assertJson(['ussdServiceOp' => 2]);
        }

        $this->postJson('/api/ussd/gwosevo?key='.self::SECRET, [
            'sessionID' => 'sq', 'ussdServiceOp' => 1, 'ussdString' => '', 'network' => '01', 'msisdn' => '233531005130',
        ])->assertJson(['ussdServiceOp' => 2]);
    }

    public function test_endpoint_fails_closed_when_no_authentication_is_configured(): void
    {
        $this->setting('ussd_gwosevo_secret', '');

        $this->gwo([], [])->assertJson(['ussdServiceOp' => 17, 'sessionID' => '']);
        $this->assertDatabaseCount('ussd_sessions', 0);
    }

    public function test_ip_allowlist_is_enforced_and_combines_with_the_secret(): void
    {
        $this->setting('ussd_gwosevo_ip_allowlist', '10.9.9.0/24');
        $this->gwo()->assertJson(['ussdServiceOp' => 17, 'sessionID' => '']);

        $this->setting('ussd_gwosevo_ip_allowlist', '127.0.0.1');
        $this->gwo()->assertJson(['ussdServiceOp' => 2]);

        // Allowlist alone (no secret) is a valid configuration.
        $this->setting('ussd_gwosevo_secret', '');
        $this->gwo(['sessionID' => 'ip-only'], [])->assertJson(['ussdServiceOp' => 2]);
    }

    public function test_the_other_gateways_credentials_do_not_authenticate_gwo_sevo(): void
    {
        $this->setting('ussd_gateway_secret', Crypt::encryptString('legacy-secret-0123456789'));
        $this->setting('ussd_gwosevo_secret', Crypt::encryptString(self::SECRET));

        $this->gwo([], ['X-Ussd-Secret' => 'legacy-secret-0123456789'])->assertJson(['ussdServiceOp' => 17, 'sessionID' => '']);
        $this->gwo()->assertJson(['ussdServiceOp' => 2]);
    }

    public function test_logs_never_contain_the_full_subscriber_number_or_secret(): void
    {
        $this->gwo();
        $this->press('1');
        $this->press('1');
        $this->press('1');
        $this->press('0244111111');

        $dump = UssdLog::all()->toJson().UssdSubscriptionEvent::all()->toJson();
        $this->assertStringNotContainsString('0531005130', $dump);
        $this->assertStringNotContainsString('233531005130', $dump);
        $this->assertStringNotContainsString('0244111111', $dump);
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringContainsString('******5130', $dump);
    }

    public function test_the_route_is_exempt_from_csrf_but_get_is_not_allowed(): void
    {
        $this->withMiddleware();
        $this->gwo()->assertOk();
        $this->get('/api/ussd/gwosevo')->assertStatus(405);
    }

    // ------------------------------------------------- payments (shared path)

    private function makePaystackConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_123', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/transaction/initialize')) {
                return Http::response([
                    'status' => true,
                    'data' => ['authorization_url' => null, 'reference' => $request->data()['reference'] ?? 'unknown'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    public function test_duplicate_confirm_deliveries_place_exactly_one_order_and_one_charge(): void
    {
        $this->makePaystackConfig();
        Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data', 'network' => 'MTN']),
            'price' => 5.00,
            'is_active' => true,
        ]);

        $this->gwo();
        foreach (['1', '1', '1', '0244111111'] as $key) {
            $this->press($key);
        }

        $first = $this->press('1');
        $first->assertJson(['ussdServiceOp' => 17, 'sessionID' => '12345'])->assertSee('Order placed successfully');

        // The gateway retries the very same confirm.
        $this->press('1')->assertJson(['ussdServiceOp' => 17]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    public function test_existing_gateway_is_unaffected(): void
    {
        $this->post('/api/ussd', [
            'sessionId' => 'legacy-654321',
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => "45*{$this->vendor->id}",
        ])->assertOk()->assertSee('CON ', false)->assertSee('Buy Data Bundle', false);
    }
}
