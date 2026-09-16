<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalFulfillment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Support\PaymentIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial suite for the public `POST /purchase` wallet-payment bypass.
 *
 * THE DEFECT (present from 2026-02-05 until this hardening)
 *
 * `/purchase` is a public route — `routes/web.php` puts it behind
 * `throttle:20,1` and `prune.purchase.tokens` only, with no auth middleware.
 * Inside `PurchaseController::store()`, the branch that actually debits a
 * wallet was guarded by:
 *
 *     if ($payWithWallet && auth('vendor')->check()) { ...debit, complete... }
 *
 * An unauthenticated request carrying `pay_with_wallet=1` therefore SKIPPED
 * that branch entirely, fell through to ordinary order creation, and reached
 * this tail further down the same method:
 *
 *     if ($payWithWallet) { $this->paymentService->completeOrder($order); }
 *
 * which marked the order paid, credited vendor wallets, and dispatched
 * external fulfillment — with no gateway payment and no wallet debit anywhere.
 * Free data bundles to any recipient number the attacker chose.
 *
 * HOW IT IS CLOSED (two independent layers)
 *
 *  1. `store()` now rejects `pay_with_wallet` outright (403) when there is no
 *     authenticated vendor, before any order is created. The fall-through tail
 *     is unreachable and has been converted into a fail-closed assertion that
 *     completes nothing.
 *  2. Even if layer 1 were bypassed, the order would carry no proof of payment
 *     (`pending_verification`), and `PaymentService::completeOrder()` now
 *     refuses to settle any order that lacks it. The wallet flow's proof is
 *     stamped only AFTER a successful debit.
 */
class PurchaseWalletBypassTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pin one browser session across calls. postJson() sends no cookies by
     * default, so without this every request looks like a brand-new browser —
     * which is a different scenario entirely (two genuine intents, two
     * legitimate purchases) from the duplicate submission being tested here.
     * Mirrors DuplicateChargePreventionOrderTest::useGuestSession().
     */
    private function useStableSession(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    }

    private function vendor(): Vendor
    {
        return Vendor::factory()->create(['is_approved' => true]);
    }

    private function product(Vendor $vendor, float $price = 88.00): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 20GB',
            'description' => json_encode(['category' => 'data', 'network' => 'MTN']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function fund(Vendor $vendor, float $amount): void
    {
        // wallet_topups tracks how much of each top-up has been `consumed`;
        // available balance is amount - consumed (see WalletService).
        WalletTopup::create([
            'vendor_id' => $vendor->id,
            'amount' => $amount,
            'consumed' => 0,
            'reference' => 'TOPUP-'.uniqid(),
            'status' => 'completed',
        ]);
    }

    private function payload(Vendor $seller, Product $product, array $overrides = []): array
    {
        return array_merge([
            'recipient_phone_number' => '0596621893',
            'vendor_id' => $seller->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
        ], $overrides);
    }

    /** Nothing was given away, in any form. */
    private function assertNothingWasSettled(Vendor ...$vendors): void
    {
        $this->assertSame(0, Order::whereIn('payment_status', ['paid', 'completed'])->count());
        $this->assertSame(0, Transaction::whereIn('payment_status', ['successful', 'completed'])->count());

        foreach ($vendors as $vendor) {
            $this->assertEqualsWithDelta(0.0, (float) $vendor->fresh()->wallet_balance, 0.001);
        }

        Queue::assertNotPushed(ProcessExternalFulfillment::class);
    }

    // -----------------------------------------------------------------

    public function test_anonymous_request_with_pay_with_wallet_is_rejected(): void
    {
        Queue::fake();
        $seller = $this->vendor();
        $product = $this->product($seller);

        $this->postJson(route('purchase'), $this->payload($seller, $product))
            ->assertStatus(403);

        // Not merely "not completed" — no order row is created at all.
        $this->assertSame(0, Order::count());
        $this->assertNothingWasSettled($seller);
    }

    public function test_a_signed_in_non_vendor_user_cannot_pay_with_a_vendor_wallet(): void
    {
        Queue::fake();
        $seller = $this->vendor();
        $product = $this->product($seller);

        // Authenticated on the default web guard — not the vendor guard.
        $this->actingAs(User::factory()->create());

        $this->postJson(route('purchase'), $this->payload($seller, $product))
            ->assertStatus(403);

        $this->assertSame(0, Order::count());
        $this->assertNothingWasSettled($seller);
    }

    public function test_a_vendor_with_insufficient_balance_completes_nothing(): void
    {
        Queue::fake();
        $seller = $this->vendor();
        $buyer = $this->vendor();
        $product = $this->product($seller, 88.00);
        $this->fund($buyer, 5.00); // nowhere near 88.00 + fee

        $this->actingAs($buyer, 'vendor');
        $this->postJson(route('purchase'), $this->payload($seller, $product))
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $order = Order::sole();
        $this->assertSame('failed', $order->payment_status);
        // The debit never happened, so the order never acquired proof.
        $this->assertNotSame(PaymentIntegrity::WALLET_VERIFIED, $order->payment_integrity_status);
        $this->assertFalse($order->allowsFulfillment());

        $this->assertEqualsWithDelta(0.0, (float) WalletTopup::sole()->consumed, 0.001);
        $this->assertNothingWasSettled($seller);
    }

    public function test_a_vendor_with_no_wallet_funds_at_all_completes_nothing(): void
    {
        Queue::fake();
        $seller = $this->vendor();
        $buyer = $this->vendor();
        $product = $this->product($seller, 10.00);

        $this->actingAs($buyer, 'vendor');
        $this->postJson(route('purchase'), $this->payload($seller, $product))
            ->assertStatus(400);

        $this->assertSame('failed', Order::sole()->payment_status);
        $this->assertNothingWasSettled($seller);
    }

    public function test_a_successful_debit_completes_the_order_exactly_once(): void
    {
        $seller = $this->vendor();
        $buyer = $this->vendor();
        $product = $this->product($seller, 10.00);
        $this->fund($buyer, 100.00);

        $this->actingAs($buyer, 'vendor');
        $this->postJson(route('purchase'), $this->payload($seller, $product))
            ->assertOk()
            ->assertJson(['success' => true]);

        $order = Order::sole();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(PaymentIntegrity::WALLET_VERIFIED, $order->payment_integrity_status);
        $this->assertSame('10.20', (string) $order->expected_amount);

        // Debited exactly once.
        $this->assertEqualsWithDelta(10.20, (float) WalletTopup::sole()->consumed, 0.001);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('payment_status', 'successful')->count());
    }

    public function test_a_duplicate_submission_neither_debits_nor_completes_twice(): void
    {
        $this->useStableSession();
        $seller = $this->vendor();
        $buyer = $this->vendor();
        $product = $this->product($seller, 10.00);
        $this->fund($buyer, 100.00);

        $this->actingAs($buyer, 'vendor');
        $payload = $this->payload($seller, $product, ['idempotency_key' => 'wallet-attempt-1']);

        $this->postJson(route('purchase'), $payload)->assertOk();
        // The identical intent submitted again — a double-click or a retry.
        $this->postJson(route('purchase'), $payload)->assertOk();

        $this->assertSame(1, Order::count(), 'one intent must produce one order');
        $this->assertEqualsWithDelta(10.20, (float) WalletTopup::sole()->consumed, 0.001);
        $this->assertSame(1, Transaction::where('payment_status', 'successful')->count());
    }

    public function test_a_product_that_does_not_belong_to_the_named_seller_is_rejected(): void
    {
        Queue::fake();
        $seller = $this->vendor();
        $otherVendor = $this->vendor();
        $buyer = $this->vendor();
        $this->fund($buyer, 100.00);

        // A cheap product owned by someone else, paired with this seller's id.
        $foreignProduct = $this->product($otherVendor, 1.00);

        $this->actingAs($buyer, 'vendor');
        $this->postJson(route('purchase'), $this->payload($seller, $foreignProduct))
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertEqualsWithDelta(0.0, (float) WalletTopup::sole()->consumed, 0.001);
        $this->assertNothingWasSettled($seller, $otherVendor);
    }

    public function test_the_wallet_charge_is_server_calculated_and_ignores_any_submitted_amount(): void
    {
        $seller = $this->vendor();
        $buyer = $this->vendor();
        $product = $this->product($seller, 50.00);
        $this->fund($buyer, 100.00);

        $this->actingAs($buyer, 'vendor');
        $this->postJson(route('purchase'), $this->payload($seller, $product, [
            'amount_paid' => 0.10,
            'amount' => 0.10,
        ]))->assertOk();

        // 50.00 base + 2% platform fee = 51.00, never the submitted 0.10.
        $order = Order::sole();
        $this->assertSame('51.00', (string) $order->expected_amount);
        $this->assertSame('51.00', (string) $order->amount_paid);
        $this->assertEqualsWithDelta(51.00, (float) WalletTopup::sole()->consumed, 0.001);
    }
}
