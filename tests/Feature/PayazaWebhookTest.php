<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayazaWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Give a fixture order the immutable financial terms every creation path
     * now freezes at creation. Derived from the order's own amount so a test
     * that overrides `amount_paid` never ends up with an order whose expected
     * amount contradicts it.
     */
    protected static function withPricingSnapshot(array $attributes): array
    {
        return array_merge([
            'expected_amount' => $attributes['amount_paid'] ?? null,
            'currency' => 'GHS',
            'pricing_snapshot_at' => now(),
        ], $attributes);
    }

    protected const SECRET = 'wh-secret-abc';

    protected const CHECK_STATUS_URL = 'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*';

    protected function makeConfig(array $overrides = []): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create(array_merge([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => self::SECRET,
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ], $overrides));
    }

    protected function makeOrder(Vendor $vendor, string $reference, array $overrides = []): Order
    {
        return Order::create(self::withPricingSnapshot(array_merge([
            'recipient_phone_number' => '0240000001',
            'mobile_money_number' => '0244123456',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'payment_reference' => $reference,
        ], $overrides)));
    }

    protected function sign(array $payload): string
    {
        return base64_encode(hash_hmac('sha512', json_encode($payload), self::SECRET, true));
    }

    protected function fakeCheckStatus(string $reference, string $status, float $amount, string $responseCode): void
    {
        Http::fake([
            self::CHECK_STATUS_URL => Http::response([
                'response_code' => $responseCode,
                'transaction_reference' => $reference,
                'transaction_amount' => $amount,
                'transaction_status' => $status,
                'currency' => 'GHS',
            ], 200),
        ]);
    }

    // 12. Valid webhook
    public function test_valid_webhook_completes_order_exactly_once(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-VALID-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-VALID-1', 'Completed', 10.00, '00');

        $payload = [
            'transaction_reference' => 'XTRA4U-PYZ-VALID-1',
            'transaction_status' => 'Funds Received',
            'amount_received' => 10.00,
        ];

        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'Processed']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
        $this->assertGreaterThan(0, $vendor->fresh()->wallet_balance);
    }

    // 13. Invalid webhook signature
    public function test_invalid_signature_is_rejected(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-BADSIG-1');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-BADSIG-1', 'transaction_status' => 'Funds Received'];

        $response = $this->withHeaders(['x-payaza-signature' => 'not-the-real-signature'])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(403);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_missing_signature_is_rejected_when_secret_is_configured(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-NOSIG-1');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-NOSIG-1', 'transaction_status' => 'Funds Received'];

        $response = $this->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(403);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // Malformed webhook: no reference at all
    public function test_malformed_webhook_without_reference_is_rejected(): void
    {
        $this->makeConfig();

        $response = $this->postJson(route('webhooks.payaza'), ['transaction_status' => 'Funds Received']);

        $response->assertStatus(400);
    }

    // Webhook still works (falls back to status-query verification) if no secret is configured.
    public function test_webhook_falls_back_to_status_query_when_no_secret_configured(): void
    {
        $this->makeConfig(['config_data' => [
            'public_key' => 'test-public-key',
            'secret_key' => '',
            'base_url' => 'https://api.payaza.africa/live',
        ]]);
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-NOSECRET-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-NOSECRET-1', 'Completed', 10.00, '00');

        $response = $this->postJson(route('webhooks.payaza'), [
            'transaction_reference' => 'XTRA4U-PYZ-NOSECRET-1',
            'transaction_status' => 'Funds Received',
        ]);

        $response->assertStatus(200)->assertJson(['message' => 'Processed']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // 14. Duplicate webhook must not double-fulfil
    public function test_duplicate_webhook_is_idempotent(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-DUP-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-DUP-1', 'Completed', 10.00, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-DUP-1', 'transaction_status' => 'Funds Received'];
        $headers = ['x-payaza-signature' => $this->sign($payload)];

        $first = $this->withHeaders($headers)->postJson(route('webhooks.payaza'), $payload);
        $first->assertStatus(200)->assertJson(['message' => 'Processed']);

        $balanceAfterFirst = $vendor->fresh()->wallet_balance;

        $second = $this->withHeaders($headers)->postJson(route('webhooks.payaza'), $payload);
        $second->assertStatus(200)->assertJson(['message' => 'Already processed']);

        $this->assertSame((float) $balanceAfterFirst, (float) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    // 15. Callback-driven verify + webhook arriving for the same transaction: exactly one fulfillment
    public function test_callback_triggered_verify_then_webhook_fulfils_exactly_once(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-RACE-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-RACE-1', 'Completed', 10.00, '00');

        // Simulates the frontend SDK callback hitting /checkout/verify first.
        $paymentService = new PaymentService;
        $verifyResult = $paymentService->checkPaymentStatus('XTRA4U-PYZ-RACE-1');
        $this->assertSame('success', $verifyResult['data']['status']);
        // Mirrors CheckoutController::verify(): the verification response is
        // put through the payment integrity guard before anything settles.
        app(\App\Services\Payments\PaymentIntegrityGuard::class)
            ->guard($order, $verifyResult, $order->payment_gateway);
        $paymentService->completeOrder($order);

        $this->assertSame('paid', $order->fresh()->payment_status);

        // Webhook arrives afterwards for the same transaction.
        $payload = ['transaction_reference' => 'XTRA4U-PYZ-RACE-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'Already processed']);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    // Amount mismatch: verified amount lower than the order's expected amount must not fulfil.
    public function test_amount_mismatch_blocks_fulfillment(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-AMT-1', ['amount_paid' => 10.00]);

        // Payaza confirms a lower amount than what the order expects.
        $this->fakeCheckStatus('XTRA4U-PYZ-AMT-1', 'Completed', 3.00, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-AMT-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        // The webhook now answers with a neutral acknowledgement (the
        // expected/confirmed figures are diagnostics for administrators, not
        // for the caller) and records the refusal on the order itself.
        $response->assertStatus(200)->assertJson(['message' => 'OK']);

        $fresh = $order->fresh();
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertSame(\App\Support\PaymentIntegrity::MISMATCH, $fresh->payment_integrity_status);
        $this->assertFalse($fresh->allowsFulfillment());
    }

    // 17. Failed verification does not fulfil
    public function test_failed_verification_does_not_fulfil(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-VERIFYFAIL-1');

        Http::fake([self::CHECK_STATUS_URL => Http::response(['response_message' => 'Internal error'], 500)]);

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-VERIFYFAIL-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'OK']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // Failed payment status recorded correctly
    public function test_verified_failed_payment_marks_order_failed(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-FAILED-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-FAILED-1', 'Failed', 0, '06');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-FAILED-1', 'transaction_status' => 'Transaction Failed'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'Failure recorded']);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    // Amount mismatch guard on AFA registrations (mirrors the Order-branch test above).
    public function test_afa_registration_amount_mismatch_blocks_fulfillment(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();

        $registration = \App\Models\AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'vendor_network_id' => 1,
            'full_name' => 'Jane Doe',
            'phone_number' => '0244123456',
            'date_of_birth' => '1990-01-01',
            'gender' => 'Female',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Trader',
            'amount' => 20.00,
            'vendor_price' => 20.00,
            'platform_commission' => 0,
            'vendor_earning' => 0,
            'reseller_earning' => 0,
            'is_reseller_order' => false,
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'id_type' => 'Ghana Card',
            'id_number' => 'GHA-000000000-0',
            'reference' => 'XTRA4U-PYZ-AFAAMT-1',
            'payment_reference' => 'XTRA4U-PYZ-AFAAMT-1',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        // Attacker pays a much smaller amount than the registration actually costs.
        $this->fakeCheckStatus('XTRA4U-PYZ-AFAAMT-1', 'Completed', 0.50, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-AFAAMT-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'Amount mismatch']);
        $this->assertSame('pending', $registration->fresh()->payment_status);
    }

    // Unmatched reference: no order/registration/result-checker order — should not error.
    public function test_unmatched_reference_returns_ok_without_processing(): void
    {
        $this->makeConfig();

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-UNKNOWN-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'OK']);
    }
}
