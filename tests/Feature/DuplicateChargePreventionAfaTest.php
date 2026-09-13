<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — AfaRegistration surface (AfaRegistrationController::store(),
 * `afa.store`). Guest/session-scoped, same as the Order surface — see
 * DuplicateChargePreventionOrderTest for why useGuestSession() is needed.
 */
class DuplicateChargePreventionAfaTest extends TestCase
{
    use RefreshDatabase;

    private string $guestSessionId;

    protected function useGuestSession(): void
    {
        $this->guestSessionId = Str::random(40);
        $this->withCredentials()->withCookie(config('session.cookie'), $this->guestSessionId);
    }

    protected function guestScope(): string
    {
        return CheckoutIntentGuard::scopeForSession($this->guestSessionId);
    }

    protected function makePaystackConfig(bool $default = true): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    protected function registerPayload(array $extra = []): array
    {
        return array_merge([
            'full_name' => 'Test User',
            'id_type' => 'ghana_card',
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Developer',
        ], $extra);
    }

    protected function existingRegistration(Vendor $vendor, array $attrs): AfaRegistration
    {
        return AfaRegistration::create(array_merge([
            'vendor_id' => $vendor->id,
            'full_name' => 'Test User',
            'id_type' => 'ghana_card',
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'amount' => 50,
            'vendor_price' => 50,
            'platform_commission' => 1,
            'vendor_earning' => 49,
            'reseller_earning' => 0,
            'is_reseller_order' => false,
            'status' => AfaRegistration::STATUS_PENDING,
            'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'reference' => AfaRegistration::generateReference(),
            'idempotency_scope' => $this->guestScope(),
        ], $attrs));
    }

    protected function fakeInitializeAlwaysSucceeds(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/transaction/initialize')) {
                $body = $request->data();

                return Http::response([
                    'status' => true,
                    'message' => 'Initialized',
                    'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'unknown'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    // A. DOUBLE SUBMIT
    public function test_double_submit_with_same_idempotency_key_creates_only_one_registration(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);
        $this->fakeInitializeAlwaysSucceeds();

        $payload = $this->registerPayload(['idempotency_key' => 'afa-intent-1']);

        $first = $this->postJson(route('afa.store', $vendor->vendor_code), $payload);
        $second = $this->postJson(route('afa.store', $vendor->vendor_code), $payload);

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);

        $this->assertDatabaseCount('afa_registrations', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    // B. CONCURRENT SUBMIT
    public function test_concurrent_submit_race_creates_only_one_registration(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);
        $this->fakeInitializeAlwaysSucceeds();

        $this->existingRegistration($vendor, [
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'afa-race',
        ]);

        $response = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'afa-race']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('afa_registrations', 1);
        Http::assertNothingSent();
    }

    // C. AMBIGUOUS PAYMENT
    public function test_ambiguous_verification_never_creates_a_second_registration(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $this->existingRegistration($vendor, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'AFA-REF-UNKNOWN',
            'idempotency_key' => 'afa-unknown',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('timed out');
            },
        ]);

        $response = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'afa-unknown']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('afa_registrations', 1);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // D. PENDING PAYMENT
    public function test_provider_pending_never_creates_a_second_registration(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $this->existingRegistration($vendor, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'AFA-REF-PENDING',
            'idempotency_key' => 'afa-pending',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200),
            'https://api.paystack.co/transaction/initialize' => Http::response(['message' => 'must never be called'], 500),
        ]);

        $response = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'afa-pending']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('afa_registrations', 1);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // Capstone
    public function test_capstone_success_discovered_during_retry_never_double_charges(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $gatewayHasSettled = false;
        Http::fake(function ($request) use (&$gatewayHasSettled) {
            $url = (string) $request->url();

            if (str_contains($url, '/transaction/initialize')) {
                $body = $request->data();

                return Http::response([
                    'status' => true,
                    'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'unknown'],
                ], 200);
            }

            if (str_contains($url, '/transaction/verify/')) {
                if (! $gatewayHasSettled) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 5000]], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $payload = $this->registerPayload(['idempotency_key' => 'afa-capstone']);
        $init = $this->postJson(route('afa.store', $vendor->vendor_code), $payload);
        $init->assertOk()->assertJson(['success' => true]);
        $registration = AfaRegistration::sole();
        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $registration->payment_status);

        $verify = $this->postJson(route('afa.verify'), ['reference' => $registration->payment_reference]);
        $verify->assertOk()->assertJson(['status' => 'pending']);

        $gatewayHasSettled = true;
        $retry = $this->postJson(route('afa.store', $vendor->vendor_code), $payload);

        $this->assertDatabaseCount('afa_registrations', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
        $retry->assertOk()->assertJson(['success' => true]);
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->fresh()->payment_status);
    }

    // F. CONFIRMED FAILURE
    public function test_confirmed_failure_allows_a_genuinely_new_attempt(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        $this->existingRegistration($vendor, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'AFA-REF-FAIL',
            'idempotency_key' => 'afa-fail',
        ]);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/transaction/verify/')) {
                return Http::response(['status' => true, 'data' => ['status' => 'failed']], 200);
            }
            if (str_contains($url, '/transaction/initialize')) {
                $body = $request->data();

                return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'x']], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $response = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'afa-fail']));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('afa_registrations', 2);
        $this->assertDatabaseHas('afa_registrations', ['payment_reference' => 'AFA-REF-FAIL', 'payment_status' => AfaRegistration::PAYMENT_FAILED]);
    }

    // G. LEGITIMATE SECOND REGISTRATION (a vendor's own re-registration flow, permitted)
    public function test_intentional_second_registration_works_after_first_completes(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);
        $this->fakeInitializeAlwaysSucceeds();

        $first = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'afa-purchase-1']));
        $first->assertOk();
        $registration1 = AfaRegistration::sole();

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 5000]], 200)]);
        app(\App\Services\AfaPaymentService::class)->completeRegistration($registration1);
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration1->fresh()->payment_status);

        $this->fakeInitializeAlwaysSucceeds();
        $second = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'afa-purchase-2']));

        $second->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('afa_registrations', 2);
    }

    // Security: cross-scope key reuse must never resolve another customer's registration.
    public function test_idempotency_key_is_scoped_and_cannot_be_reused_across_sessions(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 50]);

        // Victim's session created a registration under this key.
        $this->guestSessionId = 'victim-session-'.Str::random(10);
        $victim = $this->existingRegistration($vendor, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'AFA-VICTIM-REF',
            'idempotency_key' => 'shared-key',
        ]);

        // A different customer (different session) submits with the SAME key.
        $this->useGuestSession();
        $this->fakeInitializeAlwaysSucceeds();

        $response = $this->postJson(route('afa.store', $vendor->vendor_code), $this->registerPayload(['idempotency_key' => 'shared-key']));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('afa_registrations', 2);
        $newRegistration = AfaRegistration::where('id', '!=', $victim->id)->sole();
        $this->assertNotEquals($victim->reference, $newRegistration->reference);
    }
}
