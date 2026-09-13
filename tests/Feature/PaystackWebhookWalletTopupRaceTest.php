<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5 fix: PaystackWebhookController used to credit a wallet top-up
 * inline (creditVendor() + a plain UPDATE), never taking WalletService::
 * completeTopup()'s row lock. A webhook racing the poll endpoint,
 * PaymentReconciliationService, or another webhook delivery for the same
 * reference could double-credit the vendor's wallet. Now routed through the
 * single authoritative completion pipeline every other caller already uses.
 */
class PaystackWebhookWalletTopupRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentGatewayConfig::create([
            'gateway_name' => 'paystack',
            'gateway_type' => 'payment_collection',
            'is_active' => true,
            'is_default' => true,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_abc',
                'payment_url' => 'https://api.paystack.co',
            ],
        ]);
    }

    private function sendWebhook(string $reference): \Illuminate\Testing\TestResponse
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => $reference]];
        $signature = hash_hmac('sha512', json_encode($payload), 'sk_test_abc');

        return $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson(route('webhooks.paystack'), $payload);
    }

    public function test_webhook_never_double_credits_a_topup_already_completed_by_another_caller(): void
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => 0]);
        $reference = 'XTRA4U-TOPUP-RACE-1';

        $topup = WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendor->id,
            'amount' => 50,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
        ]);

        // Simulate the poll endpoint (or reconciler) winning the race and
        // completing this exact reference via the authoritative pipeline
        // moments before the webhook arrives.
        app(WalletService::class)->completeTopup($topup, [
            'success' => true,
            'data' => ['status' => 'success', 'amount' => 50],
        ]);
        $this->assertEquals(50, $vendor->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 1);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 5000],
            ], 200),
        ]);

        $response = $this->sendWebhook($reference);

        $response->assertStatus(200)->assertJson(['success' => true]);
        // Exactly one credit — the webhook's own race-losing attempt must not add another.
        $this->assertEquals(50, $vendor->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 1);
    }

    public function test_webhook_reconstructing_a_missing_topup_handles_a_concurrent_duplicate_reference(): void
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => 0]);
        $reference = 'XTRA4U-TOPUP-RACE-2';

        // Simulate a concurrent delivery (or the browser callback) already
        // having reconstructed/created the row for this exact reference by
        // the time this webhook's own lookup-then-create runs.
        \Illuminate\Support\Facades\Cache::put("wallet_topup:{$reference}", [
            'vendor_id' => $vendor->id,
            'amount' => 50,
        ], now()->addHours(1));

        WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendor->id,
            'amount' => 50,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 5000],
            ], 200),
        ]);

        $response = $this->sendWebhook($reference);

        $response->assertStatus(200)->assertJson(['success' => true, 'message' => 'Wallet Top-up processed']);
        $this->assertDatabaseCount('wallet_topups', 1);
        $this->assertEquals(50, $vendor->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 1);
    }
}
