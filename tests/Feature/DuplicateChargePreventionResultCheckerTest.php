<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Models\ResultCheckerOrder;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — ResultCheckerOrder surface (ResultCheckerCheckoutController::
 * initiateCheckout(), `result-checkers.checkout`). Guest/session-scoped —
 * see DuplicateChargePreventionOrderTest for why useGuestSession() and
 * withCredentials() are both required for postJson() cookies to persist.
 */
class DuplicateChargePreventionResultCheckerTest extends TestCase
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

    protected function vendorWithResultChecker(float $basePrice = 20.00): array
    {
        $vendor = Vendor::factory()->create();
        $service = NetworkService::factory()->create([
            'service_type' => 'results_checker',
            'is_active' => true,
            'base_price' => $basePrice,
        ]);
        VendorResultCheckerSetting::create([
            'vendor_id' => $vendor->id,
            'service_id' => $service->id,
            'profit_amount' => 5.00,
            'is_active' => true,
        ]);

        return [$vendor, $service];
    }

    protected function payload(NetworkService $service, array $extra = []): array
    {
        return array_merge([
            'service_id' => $service->id,
            'quantity' => 1,
            'customer_phone' => '0244000000',
        ], $extra);
    }

    protected function existingOrder(Vendor $vendor, NetworkService $service, array $attrs): ResultCheckerOrder
    {
        return ResultCheckerOrder::create(array_merge([
            'vendor_id' => $vendor->id,
            'service_id' => $service->id,
            'customer_phone' => '0244000000',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total_price' => 25.00,
            'status' => 'pending_payment',
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
                    'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'unknown'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    // A. DOUBLE SUBMIT
    public function test_double_submit_with_same_idempotency_key_creates_only_one_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();
        $this->fakeInitializeAlwaysSucceeds();

        $payload = $this->payload($service, ['idempotency_key' => 'rc-intent-1']);

        $first = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $payload);
        $second = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $payload);

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('result_checker_orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    // B. CONCURRENT SUBMIT
    public function test_concurrent_submit_race_creates_only_one_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();
        $this->fakeInitializeAlwaysSucceeds();

        $this->existingOrder($vendor, $service, [
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'rc-race',
        ]);

        $response = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $this->payload($service, ['idempotency_key' => 'rc-race']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('result_checker_orders', 1);
        Http::assertNothingSent();
    }

    // C. AMBIGUOUS PAYMENT
    public function test_ambiguous_verification_never_creates_a_second_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();

        $this->existingOrder($vendor, $service, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'RC-REF-UNKNOWN',
            'idempotency_key' => 'rc-unknown',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('timed out');
            },
        ]);

        $response = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $this->payload($service, ['idempotency_key' => 'rc-unknown']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('result_checker_orders', 1);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // D. PENDING PAYMENT
    public function test_provider_pending_never_creates_a_second_order(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();

        $this->existingOrder($vendor, $service, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'RC-REF-PENDING',
            'idempotency_key' => 'rc-pending',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200),
            'https://api.paystack.co/transaction/initialize' => Http::response(['message' => 'must never be called'], 500),
        ]);

        $response = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $this->payload($service, ['idempotency_key' => 'rc-pending']));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('result_checker_orders', 1);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // Capstone
    public function test_capstone_success_discovered_during_retry_never_double_charges(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();

        $gatewayHasSettled = false;
        Http::fake(function ($request) use (&$gatewayHasSettled) {
            $url = (string) $request->url();

            if (str_contains($url, '/transaction/initialize')) {
                $body = $request->data();

                return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.example/redirect', 'reference' => $body['reference'] ?? 'unknown']], 200);
            }

            if (str_contains($url, '/transaction/verify/')) {
                if (! $gatewayHasSettled) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 2500]], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $payload = $this->payload($service, ['idempotency_key' => 'rc-capstone']);
        $init = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $payload);
        $init->assertOk()->assertJson(['success' => true]);
        $order = ResultCheckerOrder::sole();
        $this->assertFalse($order->isPaid());

        $gatewayHasSettled = true;
        $retry = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $payload);

        $this->assertDatabaseCount('result_checker_orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
        $retry->assertOk()->assertJson(['success' => true]);
        $this->assertTrue($order->fresh()->isPaid());
    }

    // F. CONFIRMED FAILURE
    public function test_confirmed_failure_allows_a_genuinely_new_attempt(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();

        $this->existingOrder($vendor, $service, [
            'payment_gateway' => 'paystack',
            'payment_reference' => 'RC-REF-FAIL',
            'idempotency_key' => 'rc-fail',
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

        $response = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $this->payload($service, ['idempotency_key' => 'rc-fail']));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('result_checker_orders', 2);
        $this->assertDatabaseHas('result_checker_orders', ['payment_reference' => 'RC-REF-FAIL', 'status' => 'failed']);
    }

    // G. LEGITIMATE SECOND PURCHASE
    public function test_intentional_second_purchase_works_after_first_completes(): void
    {
        $this->useGuestSession();
        $this->makePaystackConfig();
        [$vendor, $service] = $this->vendorWithResultChecker();
        $this->fakeInitializeAlwaysSucceeds();

        $first = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $this->payload($service, ['idempotency_key' => 'rc-purchase-1']));
        $first->assertOk();
        $order1 = ResultCheckerOrder::sole();

        $this->fakeInitializeAlwaysSucceeds();
        $second = $this->postJson(route('result-checkers.checkout', $vendor->vendor_code), $this->payload($service, ['idempotency_key' => 'rc-purchase-2']));

        $second->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('result_checker_orders', 2);
        $this->assertNotEquals($order1->id, ResultCheckerOrder::latest('id')->first()->id);
    }
}
