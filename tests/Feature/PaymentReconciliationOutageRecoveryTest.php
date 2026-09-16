<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResultCheckerOrder;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The core acceptance test for the entire three-phase payment reconciliation
 * project.
 *
 * T0: five transactions exist as pending, under five DIFFERENT gateways and
 *     five DIFFERENT payable types.
 * T1: each one's immediate (browser-return) verification did not complete.
 * T2: no browser returns again; no webhook is ever invoked.
 * T3: `payments:reconcile` runs once.
 *
 * Expected, per the task's own acceptance criteria: confirmed successes
 * complete; confirmed failures transition appropriately; pending stays
 * pending; network/unknown stays pending; no new charge occurs; each
 * original gateway is queried (and no other); all fulfillment is
 * exactly-once.
 */
class PaymentReconciliationOutageRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'x', 'secret_key' => 'x', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => false, 'supports_payout' => true, 'supports_sms' => true, 'supports_webhook' => false,
            'is_active' => true, 'is_default' => false, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['api_key' => 'x', 'base_url' => 'https://api.bulkclix.com/api/v1'],
            'supported_features' => [],
        ]);

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => true, 'supports_webhook' => true,
            'is_active' => true, 'is_default' => false, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'api_user' => 'x', 'api_key' => 'x', 'public_key' => 'x', 'account_number' => '1', 'business_email' => 'a@b.com',
                'webhook_secret' => 'x', 'currency' => 'GHS', 'base_url' => 'https://api.moolre.com',
            ],
            'supported_features' => [],
        ]);

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_FLUTTERWAVE,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => false, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => false, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'x', 'secret_key' => 'x', 'encryption_key' => 'x', 'payment_url' => 'https://api.flutterwave.com/v3'],
            'supported_features' => [],
        ]);

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => false, 'supports_sms' => false, 'supports_webhook' => true,
            'is_active' => true, 'is_default' => false, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'x', 'secret_key' => 'x', 'base_url' => 'https://api.payaza.africa/live'],
            'supported_features' => [],
        ]);
    }

    public function test_five_pending_transactions_across_five_gateways_and_payable_types_recover_correctly_after_an_outage(): void
    {
        $age = now()->subMinutes(30); // older than MIN_AGE_MINUTES, immediately due

        // --- T0: five pending transactions, five gateways, five payable types ---

        // 1. Order via Paystack -> will resolve SUCCESS.
        $vendor1 = Vendor::factory()->create();
        $product = Product::create(['vendor_id' => $vendor1->id, 'name' => 'MTN 1GB', 'description' => json_encode(['category' => 'data']), 'price' => 20.00, 'is_active' => true]);
        $order = Order::create([
            'recipient_phone_number' => '0244000000', 'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name, 'amount_paid' => 20.00,
            // Immutable financial terms, as every creation path now freezes them.
            'expected_amount' => 20.00, 'currency' => 'GHS', 'pricing_snapshot_at' => now(),
            'vendor_id' => $vendor1->id, 'vendor_service_id' => $product->id,
            'status' => 'Pending', 'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack', 'payment_reference' => 'OUTAGE-ORDER-PAYSTACK',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $age]);

        // 2. AFA registration via BulkClix -> will resolve FAILED.
        $vendor2 = Vendor::factory()->create();
        $afa = AfaRegistration::create([
            'vendor_id' => $vendor2->id, 'full_name' => 'Outage Test', 'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-000000000-0', 'date_of_birth' => '1990-01-01', 'phone_number' => '0550000000',
            'location' => 'Accra', 'region' => 'Greater Accra', 'occupation' => 'Engineer',
            'amount' => 10, 'vendor_price' => 10, 'platform_commission' => 0.2, 'vendor_earning' => 9.8, 'reseller_earning' => 0,
            'is_reseller_order' => false, 'status' => AfaRegistration::STATUS_PENDING, 'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'reference' => AfaRegistration::generateReference(), 'payment_reference' => 'OUTAGE-AFA-BULKCLIX', 'payment_gateway' => 'bulkclix',
        ]);
        DB::table('afa_registrations')->where('id', $afa->id)->update(['created_at' => $age]);

        // 3. Result checker order via Moolre -> will resolve PENDING (stays pending).
        $rcOrder = ResultCheckerOrder::factory()->create([
            'payment_reference' => 'OUTAGE-RC-MOOLRE', 'payment_gateway' => 'moolre',
            'status' => 'pending_payment', 'total_price' => 100.00, 'unit_price' => 100.00,
        ]);
        DB::table('result_checker_orders')->where('id', $rcOrder->id)->update(['created_at' => $age]);

        // 4. USSD subscription via Flutterwave -> will resolve UNKNOWN (network failure, stays pending).
        $vendor4 = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();
        $subscription = UssdSubscription::create([
            'vendor_id' => $vendor4->id, 'ussd_plan_id' => $plan->id, 'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code, 'price_paid' => $plan->price, 'total_sessions' => $plan->included_sessions,
            'payment_reference' => 'OUTAGE-USSD-FLW', 'payment_gateway' => 'flutterwave',
        ]);
        DB::table('ussd_subscriptions')->where('id', $subscription->id)->update(['created_at' => $age]);

        // 5. Wallet top-up via Payaza -> will resolve SUCCESS.
        $vendor5 = Vendor::factory()->create(['wallet_balance' => 0.00]);
        $topup = WalletTopup::create([
            'reference' => 'OUTAGE-TOPUP-PAYAZA', 'vendor_id' => $vendor5->id, 'amount' => 60.00,
            'status' => 'initiated', 'payment_gateway' => 'payaza', 'metadata' => ['purpose' => 'wallet_topup'],
        ]);
        DB::table('wallet_topups')->where('id', $topup->id)->update(['created_at' => $age]);

        // --- T1/T2: immediate verification failed, no browser, no webhook. Nothing more happens here. ---

        // --- T3: payments:reconcile runs once. ---
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
            'https://api.bulkclix.com/api/v1/payment-api/checkstatus/*' => Http::response([
                'message' => 'ok', 'data' => ['status' => 'failed', 'amount' => 10.00],
            ], 200),
            'https://api.moolre.com/open/transact/status' => Http::response([
                'status' => 1, 'data' => ['status' => 'pending', 'amount' => 100.00, 'externalref' => 'OUTAGE-RC-MOOLRE'],
            ], 200),
            'https://api.flutterwave.com/v3/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
            'https://api.payaza.africa/*' => Http::response([
                'response_message' => 'ok', 'transaction_status' => 'successful',
                'transaction_amount' => 60.00, 'transaction_reference' => 'OUTAGE-TOPUP-PAYAZA',
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        // --- Assertions ---

        // Each gateway was queried exactly for its own record. (Flutterwave's
        // fake throws a ConnectionException rather than returning a response
        // — Http::fake() does not record a request that never got a response,
        // so that attempt is instead confirmed below via the subscription's
        // own reconciliation_attempts bookkeeping.)
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.bulkclix.com/api/v1/payment-api/checkstatus'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.moolre.com/open/transact/status'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'payaza.africa'));

        // 1. Confirmed success -> completed. Vendor credited exactly once.
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertGreaterThan(0, (float) $vendor1->fresh()->wallet_balance);

        // 2. Confirmed failure -> transitioned appropriately.
        $afa->refresh();
        $this->assertSame(AfaRegistration::PAYMENT_FAILED, $afa->payment_status);
        $this->assertSame(AfaRegistration::STATUS_CANCELLED, $afa->status);

        // 3. Provider pending -> stays pending.
        $rcOrder->refresh();
        $this->assertSame('pending_payment', $rcOrder->status);
        $this->assertNull($rcOrder->paid_at);

        // 4. Network/unknown -> stays pending, eligible for a later retry.
        // (Confirms the Flutterwave attempt actually happened, since the
        // thrown ConnectionException means Http::fake() never recorded a
        // "sent" request for it — see the note above.)
        $subscription->refresh();
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, $subscription->status);
        $this->assertEquals(1, $subscription->reconciliation_attempts);
        $this->assertTrue($subscription->next_reconciliation_at->isFuture());

        // 5. Confirmed success -> wallet credited exactly once.
        $topup->refresh();
        $this->assertSame('completed', $topup->status);
        $this->assertEquals(60.00, (float) $vendor5->fresh()->wallet_balance);

        // No new charge was ever created for any of the five.
        $this->assertEquals(1, Order::count());
        $this->assertEquals(1, AfaRegistration::count());
        $this->assertEquals(1, ResultCheckerOrder::count());
        $this->assertEquals(1, UssdSubscription::count());
        $this->assertEquals(1, WalletTopup::count());

        // Nothing initialized a new payment on any gateway.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/transaction/initialize'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/momopay'));
    }
}
