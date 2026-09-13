<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end coverage for the Payaza order-payment lifecycle that
 * PayazaWebhookTest and PayazaPaymentCollectionTest do not already exercise:
 * the browser callback route (PaymentCallbackController — the one the
 * customer's browser actually hits after the SDK popup closes), webhook/
 * callback race ordering from both directions, vendor-dashboard visibility,
 * and reseller wallet splits. See PayazaWebhookTest for webhook-side
 * idempotency/signature/amount-mismatch coverage, and
 * PayazaPaymentCollectionTest for checkout.verify coverage.
 *
 * Architecture under test: browser redirects/callbacks are never
 * authoritative on their own — PaymentCallbackController re-verifies via
 * PayazaPaymentService::verifyPayment() (server-to-server) before calling
 * the same idempotent PaymentService::completeOrder() pipeline the webhook
 * uses.
 */
class PayazaOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET = 'wh-secret-abc';

    protected const CHECK_STATUS_URL = 'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*';

    protected function makeConfig(): PaymentGatewayConfig
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
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => self::SECRET,
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ]);
    }

    protected function makeOrder(Vendor $vendor, string $reference, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'recipient_phone_number' => '0240000001',
            'mobile_money_number' => '0244123456',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'payment_reference' => $reference,
        ], $overrides));
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

    // Successful browser callback: PaymentCallbackController independently
    // verifies via Payaza's status-query API (never trusts the request's own
    // query params) and redirects to the Payment Successful page.
    public function test_successful_browser_callback_completes_order_and_redirects_to_success_page(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-CB-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-CB-1', 'Completed', 10.00, '00');

        // The callback request carries only a bare reference — exactly what a
        // customer's browser could produce, and nothing that claims success.
        $response = $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-CB-1']));

        $response->assertRedirect(route('checkout.success', ['order' => $order->id]));

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->payment_completed_at);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
        $this->assertGreaterThan(0, (float) $vendor->fresh()->wallet_balance);
    }

    // Customer never returns to XTRA4U after paying: the webhook alone
    // finalizes the order server-side, with no browser callback at all.
    public function test_webhook_finalizes_order_even_when_customer_never_returns(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-NORETURN-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-NORETURN-1', 'Completed', 10.00, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-NORETURN-1', 'transaction_status' => 'Funds Received'];
        $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload)
            ->assertStatus(200);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertGreaterThan(0, (float) $vendor->fresh()->wallet_balance);
    }

    // Webhook finalizes first; browser callback arrives later and must detect
    // the already-successful order and redirect without repeating any
    // financial action (no second verify call, no double credit).
    public function test_webhook_first_then_browser_callback_redirects_without_double_crediting(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-WEBFIRST-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-WEBFIRST-1', 'Completed', 10.00, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-WEBFIRST-1', 'transaction_status' => 'Funds Received'];
        $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload)
            ->assertStatus(200);

        $balanceAfterWebhook = (float) $vendor->fresh()->wallet_balance;

        // Browser callback arrives after the webhook already completed the order.
        // Swap in a response that would fail the test if the callback made a
        // fresh verify call instead of trusting the already-paid DB state.
        Http::fake([self::CHECK_STATUS_URL => Http::response(['response_message' => 'should not be called'], 500)]);

        $response = $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-WEBFIRST-1']));

        $response->assertRedirect(route('checkout.success', ['order' => $order->id]));
        $this->assertSame($balanceAfterWebhook, (float) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    // Browser callback verifies/finalizes first; webhook arrives later and
    // must acknowledge without repeating any financial action.
    public function test_browser_callback_first_then_webhook_acknowledges_without_double_crediting(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-CBFIRST-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-CBFIRST-1', 'Completed', 10.00, '00');

        $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-CBFIRST-1']))
            ->assertRedirect(route('checkout.success', ['order' => $order->id]));

        $balanceAfterCallback = (float) $vendor->fresh()->wallet_balance;

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-CBFIRST-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);

        $response->assertStatus(200)->assertJson(['message' => 'Already processed']);
        $this->assertSame($balanceAfterCallback, (float) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    // Duplicate browser callback (e.g. the customer double-taps back/refresh
    // on the return page) must not re-verify or re-credit.
    public function test_duplicate_browser_callback_is_idempotent(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-CBDUP-1');

        $this->fakeCheckStatus('XTRA4U-PYZ-CBDUP-1', 'Completed', 10.00, '00');

        $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-CBDUP-1']))
            ->assertRedirect(route('checkout.success', ['order' => $order->id]));

        $balanceAfterFirst = (float) $vendor->fresh()->wallet_balance;

        $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-CBDUP-1']))
            ->assertRedirect(route('checkout.success', ['order' => $order->id]));

        $this->assertSame($balanceAfterFirst, (float) $vendor->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    // Payaza verification API timeout on the browser callback path: must not
    // be treated as a failed payment, and the order must remain untouched
    // (pending) for a later webhook/reconciliation pass to resolve.
    public function test_browser_callback_verification_timeout_leaves_order_pending(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-TIMEOUT-1');

        Http::fake([
            self::CHECK_STATUS_URL => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $response = $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-TIMEOUT-1']));

        // Not a success redirect, and critically: not marked failed either.
        $response->assertStatus(302);
        $this->assertNotSame(route('checkout.success', ['order' => $order->id]), $response->headers->get('Location'));

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(0, (int) $vendor->fresh()->wallet_balance);
    }

    // Already-paid order receiving another browser callback: the fast path
    // must redirect straight to success without calling the gateway again.
    public function test_already_paid_order_receiving_callback_skips_reverification(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-ALREADYPAID-1', [
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
        ]);

        // No Http::fake at all — if the controller tried to call Payaza it
        // would throw for lack of a faked response, failing this test.
        $response = $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-ALREADYPAID-1']));

        $response->assertRedirect(route('checkout.success', ['order' => $order->id]));
    }

    // A Payaza order that has fully settled (webhook-confirmed) must show up
    // in the vendor's order list — the exact symptom reported in production.
    public function test_successfully_paid_payaza_order_is_visible_on_vendor_dashboard(): void
    {
        $this->makeConfig();
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);
        $order = $this->makeOrder($vendor, 'XTRA4U-PYZ-DASH-1', ['service_purchased' => 'MTN-2GB-PAYAZA']);

        $this->fakeCheckStatus('XTRA4U-PYZ-DASH-1', 'Completed', 10.00, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-DASH-1', 'transaction_status' => 'Funds Received'];
        $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload)
            ->assertStatus(200);

        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get(route('vendor.orders.index'));

        $response->assertStatus(200);
        $response->assertSee('MTN-2GB-PAYAZA');
    }

    // Reseller order paid via Payaza: both the product owner and the
    // reselling vendor must be credited exactly once, with a transaction
    // per party, and the order visible to the owner via the affiliate view.
    public function test_reseller_order_paid_via_payaza_credits_both_wallets_exactly_once(): void
    {
        $this->makeConfig();

        $owner = Vendor::factory()->create(['is_approved' => true, 'password' => bcrypt('password')]);
        $reseller = Vendor::factory()->create(['is_approved' => true, 'password' => bcrypt('password')]);

        $product = Product::create([
            'vendor_id' => $owner->id,
            'name' => 'MTN-2GB',
            'description' => json_encode(['network' => 'MTN', 'category' => 'data']),
            'price' => 8.00,
            'min_base_price' => 8.00,
            'is_active' => true,
            'is_resellable' => true,
        ]);

        $resellerProduct = ResellerProduct::create([
            'product_id' => $product->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_vendor_id' => $owner->id,
            'base_price' => 8.00,
            'markup_price' => 2.00,
            'selling_price' => 10.00,
            'is_active' => true,
        ]);

        $order = $this->makeOrder($reseller, 'XTRA4U-PYZ-RESELLER-1', [
            'is_reseller_order' => true,
            'reseller_product_id' => $resellerProduct->id,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
        ]);

        $this->fakeCheckStatus('XTRA4U-PYZ-RESELLER-1', 'Completed', 10.00, '00');

        $payload = ['transaction_reference' => 'XTRA4U-PYZ-RESELLER-1', 'transaction_status' => 'Funds Received'];
        $response = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);
        $response->assertStatus(200)->assertJson(['message' => 'Processed']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(7.84, round((float) $owner->fresh()->wallet_balance, 2));
        $this->assertSame(1.96, round((float) $reseller->fresh()->wallet_balance, 2));
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('vendor_id', $owner->id)->where('payment_status', 'successful')->count());
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('vendor_id', $reseller->id)->where('payment_status', 'successful')->count());

        // Duplicate webhook delivery must not credit either wallet again.
        $second = $this->withHeaders(['x-payaza-signature' => $this->sign($payload)])
            ->postJson(route('webhooks.payaza'), $payload);
        $second->assertStatus(200)->assertJson(['message' => 'Already processed']);
        $this->assertSame(7.84, round((float) $owner->fresh()->wallet_balance, 2));
        $this->assertSame(1.96, round((float) $reseller->fresh()->wallet_balance, 2));
    }

    // Incorrect reference: a callback for a reference that matches no order
    // must be rejected, never silently treated as belonging to some order.
    public function test_callback_with_unassociated_reference_is_rejected(): void
    {
        $this->makeConfig();

        $response = $this->get(route('payment.callback', ['reference' => 'XTRA4U-PYZ-NOSUCHORDER-1']));

        $response->assertStatus(404);
    }
}
