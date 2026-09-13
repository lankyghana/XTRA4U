<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 fix: PurchaseController's vendor-wallet-payment branch used to
 * create the Order and debit the wallet inside the SAME transaction, tagging
 * the row with idempotency fields but never using CheckoutIntentGuard's
 * race-safe createOrReuse() — a genuine concurrent double-click could
 * surface a raw SQL/constraint exception to the losing request (even though
 * the DB unique index already prevented a second debit). Order creation now
 * happens via createOrReuse() BEFORE the vendor is ever locked, so the
 * loser gets the same graceful existing/in-progress/success response every
 * other payment path already uses.
 */
class PurchaseControllerWalletConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PurchaseController's intent scope is session-based even for an
     * authenticated vendor (see the route's `hasSession()` branch) — pin a
     * fixed session cookie so a manually pre-created "competing" row and the
     * test's own postJson() calls resolve to the SAME scope. Without this,
     * each call would get its own freshly-generated session id (Laravel's
     * test client does not carry cookies between calls by default) and the
     * guard would never find the row this test relies on.
     */
    private function useFixedSession(): string
    {
        $sessionId = Str::random(40);
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);

        return $sessionId;
    }

    private function vendorWithWalletAndProduct(float $balance = 200.00, float $price = 150.00): array
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => $balance, 'is_approved' => true]);

        WalletTopup::create([
            'vendor_id' => $vendor->id,
            'amount' => $balance,
            'status' => 'completed',
            'reference' => 'TEST-TOPUP-'.uniqid(),
            'metadata' => ['source' => 'test'],
        ]);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'Test Service',
            'description' => 'desc',
            'price' => $price,
            'is_active' => true,
        ]);

        return [$vendor, $product];
    }

    public function test_concurrent_wallet_purchase_race_debits_exactly_once_and_never_leaks_a_sql_error(): void
    {
        [$vendor, $product] = $this->vendorWithWalletAndProduct();
        $sessionId = $this->useFixedSession();
        $this->actingAs($vendor, 'vendor');

        $intentKey = 'wallet-quickbuy-race';
        $intentScope = CheckoutIntentGuard::scopeForSession($sessionId);

        // Simulate a concurrent duplicate submission (double-click) already
        // having won the race and inserted the row for this exact intent —
        // still mid-flight, so no gateway/status resolution yet either.
        Order::create([
            'recipient_phone_number' => '0551234567',
            'mobile_money_number' => '0551234567',
            'service_purchased' => $product->name,
            'amount_paid' => 153.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_source' => 'wallet',
            'idempotency_scope' => $intentScope,
            'idempotency_key' => $intentKey,
        ]);

        $response = $this->postJson(route('purchase'), [
            'recipient_phone_number' => '0551234567',
            'mobile_money_number' => '0551234567',
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
            'idempotency_key' => $intentKey,
        ]);

        // No raw SQL/constraint exception (500) — a graceful JSON response.
        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Exactly one order, and the wallet was never touched by this
        // (losing) request — the winning request is responsible for the debit.
        $this->assertDatabaseCount('orders', 1);
        $vendor->refresh();
        $this->assertEquals(200.00, (float) $vendor->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 0);
    }

    public function test_wallet_purchase_double_submit_with_same_key_debits_exactly_once(): void
    {
        [$vendor, $product] = $this->vendorWithWalletAndProduct();
        $this->useFixedSession();
        $this->actingAs($vendor, 'vendor');

        $payload = [
            'recipient_phone_number' => '0551234567',
            'mobile_money_number' => '0551234567',
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
            'idempotency_key' => 'wallet-quickbuy-double-submit',
        ];

        $first = $this->postJson(route('purchase'), $payload);
        $second = $this->postJson(route('purchase'), $payload);

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseCount('orders', 1);

        $platformFee = round(150.00 * 0.02, 2);
        $walletCharge = round(150.00 + $platformFee, 2);

        $vendor->refresh();
        $this->assertEquals(200.00 - $walletCharge, (float) $vendor->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 1);
    }

    public function test_confirmed_failed_wallet_order_allows_a_genuinely_new_attempt(): void
    {
        [$vendor, $product] = $this->vendorWithWalletAndProduct(balance: 200.00);
        $sessionId = $this->useFixedSession();
        $this->actingAs($vendor, 'vendor');

        $intentKey = 'wallet-quickbuy-retry';
        $intentScope = CheckoutIntentGuard::scopeForSession($sessionId);

        Order::create([
            'recipient_phone_number' => '0551234567',
            'mobile_money_number' => '0551234567',
            'service_purchased' => $product->name,
            'amount_paid' => 153.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Failed',
            'payment_status' => 'failed',
            'payment_source' => 'wallet',
            'idempotency_scope' => $intentScope,
            'idempotency_key' => $intentKey,
        ]);

        $response = $this->postJson(route('purchase'), [
            'recipient_phone_number' => '0551234567',
            'mobile_money_number' => '0551234567',
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
            'idempotency_key' => $intentKey,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('orders', 2);

        $platformFee = round(150.00 * 0.02, 2);
        $walletCharge = round(150.00 + $platformFee, 2);
        $vendor->refresh();
        $this->assertEquals(200.00 - $walletCharge, (float) $vendor->wallet_balance);
        $this->assertDatabaseCount('wallet_ledgers', 1);
    }
}
