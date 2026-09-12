<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\WalletLedger;
use App\Models\WalletTopup;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationServiceWalletTopupTest extends TestCase
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

    protected function makePayazaConfig(bool $default = false): PaymentGatewayConfig
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

    protected function pendingTopup(string $reference, float $amount = 60.00, string $gateway = 'paystack'): WalletTopup
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        return WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendor->id,
            'amount' => $amount,
            'status' => 'initiated',
            'payment_gateway' => $gateway,
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);
    }

    protected function service(): PaymentReconciliationService
    {
        return app(PaymentReconciliationService::class);
    }

    // A. SUCCESS
    public function test_success_credits_exactly_once(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-A', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($topup);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertEquals(60.00, (float) $topup->vendor->fresh()->wallet_balance);
    }

    // B. FAILED
    public function test_authoritative_failure_marks_failed(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-B', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 6000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($topup);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $this->assertSame('failed', $topup->fresh()->status);
        $this->assertEquals(0.0, (float) $topup->vendor->fresh()->wallet_balance);
    }

    // C. PENDING
    public function test_provider_pending_stays_pending(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-C', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($topup);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('initiated', $topup->fresh()->status);
    }

    // D. UNKNOWN / network error
    public function test_network_error_stays_unresolved(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-D', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $outcome = $this->service()->reconcile($topup);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame('initiated', $topup->fresh()->status);
    }

    // E. ORIGINAL GATEWAY after default changes
    public function test_original_gateway_is_queried_after_default_changes(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);
        $topup = $this->pendingTopup('WT-E', 60.00, 'paystack');

        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 6000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $outcome = $this->service()->reconcile($topup);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payaza'));
        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
    }

    // F. DUPLICATE RECONCILIATION
    public function test_duplicate_reconciliation_credits_exactly_once(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-F', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        $this->service()->reconcile($topup);
        $this->service()->reconcile($topup->fresh());

        $this->assertEquals(60.00, (float) $topup->vendor->fresh()->wallet_balance);
        $this->assertEquals(1, WalletLedger::where('vendor_id', $topup->vendor_id)->where('type', 'credit')->count());
    }

    // G. WEBHOOK RACE
    public function test_webhook_crediting_first_is_not_double_credited(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-G', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 6000],
            ], 200),
        ]);

        // Simulate the webhook crediting first via the same shared pipeline.
        app(\App\Services\WalletService::class)->completeTopup($topup->fresh(), [
            'success' => true, 'data' => ['status' => 'success', 'amount' => 6000],
        ]);
        $this->assertEquals(60.00, (float) $topup->vendor->fresh()->wallet_balance);

        $this->service()->reconcile($topup->fresh());

        $this->assertEquals(60.00, (float) $topup->vendor->fresh()->wallet_balance, 'Must not double-credit.');
        $this->assertEquals(1, WalletLedger::where('vendor_id', $topup->vendor_id)->where('type', 'credit')->count());
    }

    // H. INTEGRITY MISMATCH
    public function test_success_with_wrong_amount_does_not_credit(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-H', 60.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 100],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($topup);

        $this->assertSame(PaymentReconciliationService::OUTCOME_INTEGRITY_MISMATCH, $outcome);
        $this->assertEquals(0.0, (float) $topup->vendor->fresh()->wallet_balance);
        $this->assertSame('initiated', $topup->fresh()->status);
    }

    // Legacy NULL-gateway top-up: never guessed, parked for manual review
    public function test_legacy_null_gateway_topup_is_parked_without_any_gateway_call(): void
    {
        $this->makePaystackConfig();
        $topup = $this->pendingTopup('WT-LEGACY', 60.00);
        \Illuminate\Support\Facades\DB::table('wallet_topups')->where('id', $topup->id)->update(['payment_gateway' => null]);

        Http::fake();

        $outcome = $this->service()->reconcile($topup->fresh());

        Http::assertNothingSent();
        $this->assertSame(PaymentReconciliationService::OUTCOME_NO_GATEWAY, $outcome);
        $this->assertSame('initiated', $topup->fresh()->status);
        $this->assertEquals(0.0, (float) $topup->vendor->fresh()->wallet_balance);
    }
}
