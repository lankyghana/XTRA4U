<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4 — WalletTopup surface (VendorWalletController::initiateTopup(),
 * `vendor.wallet.topup`). Vendor-authenticated — actingAs persists across
 * requests without any cookie juggling.
 *
 * Unlike UssdSubscriptionPurchaseService, WalletTopup's reconciliation path
 * (PaymentReconciliationService::reconcileWalletTopup()) calls
 * GatewayManager::verifyCollectionWithGateway() directly — not
 * PaymentService — so these tests fake the real Paystack HTTP endpoints
 * rather than mocking PaymentService, exactly like the Order/AFA/
 * ResultChecker surfaces.
 */
class DuplicateChargePreventionWalletTopupTest extends TestCase
{
    use RefreshDatabase;

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

    protected function existingTopup(Vendor $vendor, array $attrs): WalletTopup
    {
        return WalletTopup::create(array_merge([
            'reference' => 'topup-'.uniqid(),
            'vendor_id' => $vendor->id,
            'amount' => 50.00,
            'status' => 'initiated',
            'metadata' => ['purpose' => 'wallet_topup'],
            'idempotency_scope' => CheckoutIntentGuard::scopeForVendor($vendor->id),
        ], $attrs));
    }

    protected function topup(Vendor $vendor, float $amount, ?string $idempotencyKey)
    {
        return $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.wallet.topup'), array_filter([
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
            ], fn ($v) => $v !== null));
    }

    // A. DOUBLE SUBMIT
    public function test_double_submit_with_same_idempotency_key_creates_only_one_topup(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $vendor = Vendor::factory()->create();

        $first = $this->topup($vendor, 50.00, 'wallet-intent-1');
        $second = $this->topup($vendor, 50.00, 'wallet-intent-1');

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('wallet_topups', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    // B. CONCURRENT SUBMIT
    public function test_concurrent_submit_race_creates_only_one_topup(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $vendor = Vendor::factory()->create();

        // No payment_gateway yet — mirrors a row still mid-initiation when
        // the race happens, so no reconcile() call is even attempted.
        $this->existingTopup($vendor, [
            'idempotency_key' => 'wallet-race',
        ]);

        $response = $this->topup($vendor, 50.00, 'wallet-race');

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('wallet_topups', 1);
        Http::assertNothingSent();
    }

    // C. AMBIGUOUS PAYMENT
    public function test_ambiguous_verification_never_creates_a_second_topup(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();

        $this->existingTopup($vendor, [
            'reference' => 'wallet-ref-unknown',
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'wallet-unknown',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('timed out');
            },
        ]);

        $response = $this->topup($vendor, 50.00, 'wallet-unknown');

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('wallet_topups', 1);
        $this->assertSame('initiated', WalletTopup::sole()->status);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // D. PENDING PAYMENT
    public function test_provider_pending_never_creates_a_second_topup(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();

        $this->existingTopup($vendor, [
            'reference' => 'wallet-ref-pending',
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'wallet-pending',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200),
            'https://api.paystack.co/transaction/initialize' => Http::response(['message' => 'must never be called'], 500),
        ]);

        $response = $this->topup($vendor, 50.00, 'wallet-pending');

        $response->assertOk()->assertJson(['success' => true, 'status' => 'confirming']);
        $this->assertDatabaseCount('wallet_topups', 1);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
    }

    // Capstone
    public function test_capstone_success_discovered_during_retry_never_double_credits(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['wallet_balance' => 0]);

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

                return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 5000]], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $init = $this->topup($vendor, 50.00, 'wallet-capstone');
        $init->assertOk()->assertJson(['success' => true]);
        $topup = WalletTopup::sole();
        $this->assertSame('initiated', $topup->status);

        $gatewayHasSettled = true;
        $retry = $this->topup($vendor, 50.00, 'wallet-capstone');

        $this->assertDatabaseCount('wallet_topups', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
        $retry->assertOk()->assertJson(['success' => true, 'status' => 'completed']);
        $this->assertSame('completed', $topup->fresh()->status);

        // Exactly one credit — never double.
        $vendor->refresh();
        $this->assertEquals(50.00, (float) $vendor->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 1);
    }

    // F. CONFIRMED FAILURE
    public function test_confirmed_failure_allows_a_genuinely_new_attempt(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();

        $this->existingTopup($vendor, [
            'reference' => 'wallet-ref-fail',
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'wallet-fail',
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

        $response = $this->topup($vendor, 50.00, 'wallet-fail');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('wallet_topups', 2);
        $this->assertDatabaseHas('wallet_topups', ['reference' => 'wallet-ref-fail', 'status' => 'failed']);
    }

    // G. LEGITIMATE SECOND TOPUP — two intentional top-ups of the SAME amount must both work.
    public function test_two_intentional_topups_of_the_same_amount_both_work(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['wallet_balance' => 0]);
        $this->fakeInitializeAlwaysSucceeds();

        $first = $this->topup($vendor, 50.00, 'wallet-purchase-1');
        $first->assertOk();
        $topup1 = WalletTopup::sole();

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 5000]], 200)]);
        app(WalletService::class)->completeTopup($topup1, ['success' => true, 'data' => ['status' => 'success', 'amount' => 50.0]]);
        $this->assertSame('completed', $topup1->fresh()->status);

        $this->fakeInitializeAlwaysSucceeds();
        $second = $this->topup($vendor, 50.00, 'wallet-purchase-2');

        $second->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('wallet_topups', 2);

        $topup2 = WalletTopup::where('id', '!=', $topup1->id)->sole();
        app(WalletService::class)->completeTopup($topup2, ['success' => true, 'data' => ['status' => 'success', 'amount' => 50.0]]);

        // Both top-ups credited independently — exactly once each.
        $this->assertEquals(100.00, (float) $vendor->fresh()->wallet_balance);
    }

    // Security: cross-vendor key reuse must never resolve another vendor's top-up.
    public function test_idempotency_key_is_scoped_and_cannot_be_reused_across_vendors(): void
    {
        $this->makePaystackConfig();
        $this->fakeInitializeAlwaysSucceeds();
        $victim = Vendor::factory()->create();

        $victimTopup = $this->existingTopup($victim, [
            'reference' => 'wallet-victim-ref',
            'payment_gateway' => 'paystack',
            'idempotency_key' => 'shared-wallet-key',
        ]);

        $attacker = Vendor::factory()->create();
        $response = $this->topup($attacker, 50.00, 'shared-wallet-key');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('wallet_topups', 2);
        $newTopup = WalletTopup::where('id', '!=', $victimTopup->id)->sole();
        $this->assertSame($attacker->id, $newTopup->vendor_id);
    }
}
