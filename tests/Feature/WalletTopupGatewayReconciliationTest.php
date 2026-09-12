<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\WalletLedger;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 2 payment reconciliation hardening — wallet top-up gateway
 * persistence coverage.
 */
class WalletTopupGatewayReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(bool $default): PaymentGatewayConfig
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

    protected function makePayazaConfig(bool $default): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ]);
    }

    // -----------------------------------------------------------------
    // 1 & 2: new top-ups store the gateway that actually created them
    // -----------------------------------------------------------------

    public function test_new_paystack_topup_stores_payment_gateway(): void
    {
        $this->makePaystackConfig(default: true);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Initialized',
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $this->actingAs($vendor, 'vendor');

        $resp = $this->postJson(route('vendor.wallet.topup'), ['amount' => 50.00]);
        $resp->assertOk()->assertJson(['success' => true, 'gateway_name' => 'paystack']);

        $topup = WalletTopup::where('vendor_id', $vendor->id)->first();
        $this->assertNotNull($topup);
        $this->assertSame('paystack', $topup->payment_gateway);
    }

    public function test_new_payaza_topup_stores_payment_gateway(): void
    {
        $this->makePayazaConfig(default: true);

        $vendor = Vendor::factory()->create(['phone_number' => '0244000000']);
        $this->actingAs($vendor, 'vendor');

        // Payaza's Web Checkout SDK needs no server-to-server initialize call.
        $resp = $this->postJson(route('vendor.wallet.topup'), ['amount' => 30.00]);
        $resp->assertOk()->assertJson(['success' => true, 'gateway_name' => 'payaza']);

        $topup = WalletTopup::where('vendor_id', $vendor->id)->first();
        $this->assertNotNull($topup);
        $this->assertSame('payaza', $topup->payment_gateway);
    }

    // -----------------------------------------------------------------
    // 3, 4 & 5: default-gateway-switch immunity
    // -----------------------------------------------------------------

    public function test_switching_default_gateway_does_not_affect_verification_of_an_existing_topup(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create();
        $this->actingAs($vendor, 'vendor');

        $init = $this->postJson(route('vendor.wallet.topup'), ['amount' => 50.00])->json();
        $reference = $init['reference'];

        // Admin switches the platform default to Payaza.
        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 5000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $resp = $this->get(route('vendor.wallet.topup.callback', ['reference' => $reference]), [
            'Accept' => 'application/json',
        ]);

        // 4. Original gateway (Paystack) is queried.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.paystack.co/transaction/verify'));
        // 5. New default (Payaza) is NOT queried.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payaza'));

        $resp->assertOk();
        $vendor->refresh();
        $this->assertEquals(50.00, (float) $vendor->wallet_balance);
    }

    // -----------------------------------------------------------------
    // 6 & 7: network uncertainty vs authoritative failure
    // -----------------------------------------------------------------

    public function test_network_timeout_keeps_topup_pending(): void
    {
        $this->makePaystackConfig(default: true);
        $vendor = Vendor::factory()->create();

        $topup = WalletTopup::create([
            'reference' => 'TOPUP-TIMEOUT',
            'vendor_id' => $vendor->id,
            'amount' => 40.00,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $resp = $this->get(route('vendor.wallet.topup.callback', ['reference' => 'TOPUP-TIMEOUT']), [
            'Accept' => 'application/json',
        ]);

        $resp->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame('initiated', $topup->fresh()->status);

        $vendor->refresh();
        $this->assertEquals(0.0, (float) $vendor->wallet_balance);
    }

    public function test_explicit_provider_failure_may_fail_the_topup(): void
    {
        $this->makePaystackConfig(default: true);
        $vendor = Vendor::factory()->create();

        $topup = WalletTopup::create([
            'reference' => 'TOPUP-DECLINED',
            'vendor_id' => $vendor->id,
            'amount' => 40.00,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'amount' => 4000],
            ], 200),
        ]);

        $resp = $this->get(route('vendor.wallet.topup.callback', ['reference' => 'TOPUP-DECLINED']), [
            'Accept' => 'application/json',
        ]);

        $resp->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame('failed', $topup->fresh()->status);

        $vendor->refresh();
        $this->assertEquals(0.0, (float) $vendor->wallet_balance);
    }

    // -----------------------------------------------------------------
    // 8 & 9: exactly-once crediting
    // -----------------------------------------------------------------

    public function test_verified_success_credits_wallet_exactly_once(): void
    {
        $this->makePaystackConfig(default: true);
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        WalletTopup::create([
            'reference' => 'TOPUP-ONCE',
            'vendor_id' => $vendor->id,
            'amount' => 60.00,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 6000, 'metadata' => ['vendor_id' => $vendor->id, 'purpose' => 'wallet_topup']],
            ], 200),
        ]);

        $this->get(route('vendor.wallet.topup.callback', ['reference' => 'TOPUP-ONCE']), ['Accept' => 'application/json'])
            ->assertOk();

        $vendor->refresh();
        $this->assertEquals(60.00, (float) $vendor->wallet_balance);
        $this->assertEquals(1, WalletLedger::where('vendor_id', $vendor->id)->where('type', 'credit')->count());
    }

    public function test_duplicate_callback_cannot_credit_twice(): void
    {
        $this->makePaystackConfig(default: true);
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        WalletTopup::create([
            'reference' => 'TOPUP-DUPLICATE',
            'vendor_id' => $vendor->id,
            'amount' => 60.00,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 6000, 'metadata' => ['vendor_id' => $vendor->id, 'purpose' => 'wallet_topup']],
            ], 200),
        ]);

        // First callback credits the wallet.
        $this->get(route('vendor.wallet.topup.callback', ['reference' => 'TOPUP-DUPLICATE']), ['Accept' => 'application/json'])
            ->assertOk();

        // A second, duplicate callback (webhook + browser race, replay, retry)
        // for the same reference must not credit again.
        $this->get(route('vendor.wallet.topup.callback', ['reference' => 'TOPUP-DUPLICATE']), ['Accept' => 'application/json'])
            ->assertOk();

        $vendor->refresh();
        $this->assertEquals(60.00, (float) $vendor->wallet_balance, 'Wallet must be credited exactly once, not twice.');
        $this->assertEquals(1, WalletLedger::where('vendor_id', $vendor->id)->where('type', 'credit')->count());
    }

    // -----------------------------------------------------------------
    // 10: legacy NULL-gateway rows are never guessed
    // -----------------------------------------------------------------

    public function test_legacy_null_gateway_topup_is_not_guessed_against_current_default(): void
    {
        $this->makePaystackConfig(default: true);
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        // Simulates a row created before the payment_gateway column existed.
        $topup = WalletTopup::create([
            'reference' => 'TOPUP-LEGACY',
            'vendor_id' => $vendor->id,
            'amount' => 60.00,
            'status' => 'initiated',
            'payment_gateway' => null,
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        // If the code guessed and queried the current default (Paystack),
        // this fake would answer "success" and the assertions below would
        // catch the wallet being wrongly credited.
        Http::fake([
            'https://api.paystack.co/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $resp = $this->get(route('vendor.wallet.topup.callback', ['reference' => 'TOPUP-LEGACY']), [
            'Accept' => 'application/json',
        ]);

        Http::assertNothingSent();

        $resp->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame('initiated', $topup->fresh()->status);

        $vendor->refresh();
        $this->assertEquals(0.0, (float) $vendor->wallet_balance);
    }

    public function test_legacy_null_gateway_topup_status_poll_is_not_guessed_against_current_default(): void
    {
        $this->makePaystackConfig(default: true);
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);
        $this->actingAs($vendor, 'vendor');

        $topup = WalletTopup::create([
            'reference' => 'TOPUP-LEGACY-STATUS',
            'vendor_id' => $vendor->id,
            'amount' => 60.00,
            'status' => 'initiated',
            'payment_gateway' => null,
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        Http::fake([
            'https://api.paystack.co/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $resp = $this->getJson(route('vendor.wallet.topup.status', ['reference' => 'TOPUP-LEGACY-STATUS']));

        Http::assertNothingSent();
        $resp->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame('initiated', $topup->fresh()->status);
        $this->assertEquals(0.0, (float) $vendor->fresh()->wallet_balance);
    }
}
