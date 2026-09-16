<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Payments\OrderPricingSnapshot;
use App\Services\PaymentService;
use App\Support\PaymentIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security regression suite for the pricing model and its authority boundaries.
 *
 *   MAIN VENDOR  sets the authoritative price of their own product. A low
 *                price is a legitimate business decision, not an exploit —
 *                nothing here imposes a platform minimum.
 *   RESELLER     controls ONLY their markup. They may never reduce, replace or
 *                restate the main vendor's base price.
 *   CUSTOMER     controls neither.
 *   ORDER        freezes the resulting terms at creation. Later price and
 *                markup changes apply to NEW orders only.
 */
class PricingAuthorityAndSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function approvedVendor(): Vendor
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
            'is_resellable' => true,
        ]);
    }

    private function listing(Product $product, Vendor $reseller, float $markup = 1.00): ResellerProduct
    {
        return ResellerProduct::create([
            'product_id' => $product->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_vendor_id' => $product->vendor_id,
            'base_price' => $product->price,
            'markup_price' => $markup,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // Main vendor pricing authority
    // -----------------------------------------------------------------

    public function test_a_main_vendor_may_price_their_own_product_as_low_as_they_choose(): void
    {
        // GHS 2.00 for a 1GB bundle — the shape flagged in the audit (#21196).
        // The owner is entitled to set this; the platform does not override it.
        $vendor = $this->approvedVendor();
        $this->actingAs($vendor, 'vendor');

        $this->post(route('vendor.products.store'), [
            'name' => 'MTN 1GB',
            'price' => 2.00,
            'category' => 'data',
        ])->assertRedirect();

        $this->assertSame('2.00', (string) Product::firstOrFail()->price);
    }

    public function test_a_vendor_cannot_change_another_vendors_product_price(): void
    {
        $owner = $this->approvedVendor();
        $attacker = $this->approvedVendor();
        $product = $this->product($owner, 88.00);

        // A crafted request from a signed-in vendor, aimed at someone else's
        // product id.
        $this->actingAs($attacker, 'vendor');
        $this->put(route('vendor.products.update', $product->id), [
            'name' => 'MTN 20GB',
            'price' => 0.10,
            'category' => 'data',
        ])->assertNotFound();

        $this->assertSame('88.00', (string) $product->fresh()->price);
    }

    public function test_a_vendor_cannot_delete_another_vendors_product(): void
    {
        $owner = $this->approvedVendor();
        $attacker = $this->approvedVendor();
        $product = $this->product($owner);

        $this->actingAs($attacker, 'vendor');
        $this->delete(route('vendor.products.destroy', $product->id))->assertNotFound();

        $this->assertNotSoftDeleted($product);
    }

    public function test_product_ownership_cannot_be_reassigned_by_mass_assignment(): void
    {
        $owner = $this->approvedVendor();
        $attacker = $this->approvedVendor();
        $product = $this->product($owner);

        $product->update(['vendor_id' => $attacker->id, 'price' => 50.00]);

        $this->assertSame($owner->id, $product->fresh()->vendor_id, 'ownership must be immutable');
        $this->assertSame('50.00', (string) $product->fresh()->price, 'the owner may still change their own price');
    }

    // -----------------------------------------------------------------
    // Reseller authority is limited to markup
    // -----------------------------------------------------------------

    public function test_a_reseller_crafting_a_base_price_cannot_change_what_the_owner_is_owed(): void
    {
        $owner = $this->approvedVendor();
        $reseller = $this->approvedVendor();
        $product = $this->product($owner, 88.00);
        $listing = $this->listing($product, $reseller, 1.00);

        // The crafted request: base_price and selling_price supplied directly,
        // attempting to keep the 20GB bundle while paying the owner GHS 0.10.
        $this->actingAs($reseller, 'vendor');
        $this->patch(route('vendor.reseller.update', $listing->id), [
            'markup_price' => 1.00,
            'base_price' => 0.10,
            'selling_price' => 1.10,
            'is_active' => true,
        ]);

        $fresh = $listing->fresh();
        $this->assertSame('88.00', (string) $fresh->base_price, "the main vendor's base price is untouchable");
        $this->assertSame('1.00', (string) $fresh->markup_price);
        $this->assertSame('89.00', (string) $fresh->selling_price, 'selling price is always base + markup');
    }

    public function test_base_price_cannot_be_moved_by_a_direct_model_update(): void
    {
        $owner = $this->approvedVendor();
        $reseller = $this->approvedVendor();
        $listing = $this->listing($this->product($owner, 88.00), $reseller, 1.00);

        $listing->update(['base_price' => 10.00]);

        $this->assertSame('88.00', (string) $listing->fresh()->base_price);
        $this->assertSame('89.00', (string) $listing->fresh()->selling_price);
    }

    public function test_the_owner_changing_their_price_syncs_reseller_base_prices(): void
    {
        $owner = $this->approvedVendor();
        $reseller = $this->approvedVendor();
        $product = $this->product($owner, 88.00);
        $listing = $this->listing($product, $reseller, 1.00);

        $this->actingAs($owner, 'vendor');
        $this->put(route('vendor.products.update', $product->id), [
            'name' => $product->name,
            'price' => 95.00,
            'category' => 'data',
            'is_active' => 1,
        ]);

        $fresh = $listing->fresh();
        $this->assertSame('95.00', (string) $fresh->base_price, 'the authoritative sync is the one allowed way base price moves');
        $this->assertSame('1.00', (string) $fresh->markup_price, "the reseller's markup is theirs and is preserved");
        $this->assertSame('96.00', (string) $fresh->selling_price);
    }

    public function test_a_negative_markup_cannot_drag_a_sale_below_the_owners_base_price(): void
    {
        $owner = $this->approvedVendor();
        $reseller = $this->approvedVendor();
        $listing = $this->listing($this->product($owner, 88.00), $reseller, 1.00);

        $listing->markup_price = -50.00;
        $listing->save();

        $this->assertSame('0.00', (string) $listing->fresh()->markup_price);
        $this->assertSame('88.00', (string) $listing->fresh()->selling_price);
    }

    // -----------------------------------------------------------------
    // Order snapshot immutability
    // -----------------------------------------------------------------

    public function test_a_later_product_price_change_does_not_alter_an_existing_orders_terms(): void
    {
        $owner = $this->approvedVendor();
        $product = $this->product($owner, 88.00);

        $snapshot = OrderPricingSnapshot::forOwnedProduct($product);
        $order = Order::create([
            'recipient_phone_number' => '0596621893',
            'mobile_money_number' => '0596621893',
            'service_purchased' => $product->name,
            'amount_paid' => $snapshot['expected_amount'],
            ...$snapshot,
            'vendor_id' => $owner->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
        ]);

        // The main vendor raises their price after the order exists.
        $product->update(['price' => 150.00]);

        $fresh = $order->fresh();
        $this->assertSame('88.00', (string) $fresh->expected_amount);
        $this->assertSame('88.00', (string) $fresh->base_price);
        $this->assertSame('GHS', $fresh->currency);
    }

    public function test_settlement_uses_the_frozen_split_not_live_reseller_pricing(): void
    {
        // This is the #83 mechanism: the order's own financial records showed
        // ~GHS 89 of value derived from the LIVE listing at completion time,
        // while only a fraction had actually been collected. Settlement must
        // now pay out strictly what the order froze.
        $owner = $this->approvedVendor();
        $reseller = $this->approvedVendor();
        $product = $this->product($owner, 88.00);
        $listing = $this->listing($product, $reseller, 1.00);

        $snapshot = OrderPricingSnapshot::forResellerListing($listing);
        $order = Order::create([
            'recipient_phone_number' => '0596621893',
            'mobile_money_number' => '0596621893',
            'service_purchased' => $product->name,
            'amount_paid' => $snapshot['expected_amount'],
            ...$snapshot,
            'vendor_id' => $reseller->id,
            'vendor_service_id' => $product->id,
            'reseller_product_id' => $listing->id,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'is_reseller_order' => true,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
        ]);

        // Both parties re-price AFTER the order was created.
        $product->update(['price' => 500.00]);
        $listing->fresh()->update(['markup_price' => 100.00]);

        app(\App\Services\Payments\PaymentIntegrityGuard::class)->stampTrustedSource(
            $order,
            PaymentIntegrity::ADMIN_CONFIRMED,
            'test fixture: payment proven out of band'
        );

        $this->assertTrue(app(PaymentService::class)->completeOrder($order->fresh()));

        $fresh = $order->fresh();
        // Frozen terms, not the re-priced listing (which would have been
        // 500 + 100 = GHS 600 of payouts against an GHS 89 payment).
        $this->assertSame('88.00', (string) $fresh->base_price);
        $this->assertSame('1.00', (string) $fresh->markup_price);
        $this->assertEqualsWithDelta(86.24, (float) $fresh->owner_earning, 0.001);
        $this->assertEqualsWithDelta(0.98, (float) $fresh->reseller_earning, 0.001);
        $this->assertEqualsWithDelta(86.24, (float) $owner->fresh()->wallet_balance, 0.001);
        $this->assertEqualsWithDelta(0.98, (float) $reseller->fresh()->wallet_balance, 0.001);

        // Total credited never exceeds what the order was owed.
        $totalCredited = (float) $owner->fresh()->wallet_balance + (float) $reseller->fresh()->wallet_balance;
        $this->assertLessThanOrEqual(89.00, round($totalCredited + (float) $fresh->platform_commission, 2));
    }

    // -----------------------------------------------------------------
    // Product history preservation
    // -----------------------------------------------------------------

    public function test_deleting_a_product_preserves_the_historical_orders_pricing_and_identity(): void
    {
        $owner = $this->approvedVendor();
        $product = $this->product($owner, 88.00);

        $snapshot = OrderPricingSnapshot::forOwnedProduct($product);
        $order = Order::create([
            'recipient_phone_number' => '0596621893',
            'mobile_money_number' => '0596621893',
            'service_purchased' => $product->name,
            'amount_paid' => $snapshot['expected_amount'],
            ...$snapshot,
            'vendor_id' => $owner->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($owner, 'vendor');
        $this->delete(route('vendor.products.destroy', $product->id))->assertRedirect();

        $this->assertSoftDeleted($product);

        $fresh = $order->fresh();
        // The order keeps its own frozen economics...
        $this->assertSame($product->id, $fresh->vendor_service_id);
        $this->assertSame('88.00', (string) $fresh->expected_amount);
        // ...and the product remains resolvable for auditing.
        $this->assertNotNull($fresh->service);
        $this->assertSame('MTN 20GB', $fresh->service->name);

        // But it is gone from sale.
        $this->assertSame(0, Product::where('id', $product->id)->count());
        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    // -----------------------------------------------------------------
    // Wallet purchases: the other trusted payment source
    // -----------------------------------------------------------------

    public function test_an_unauthenticated_request_cannot_pay_with_a_wallet(): void
    {
        // Before this fix, /purchase (a public route) accepted
        // pay_with_wallet=1 from anyone: the wallet branch was skipped because
        // it requires vendor auth, and the request fell through to a tail that
        // completed the order outright — no gateway payment, no wallet debit,
        // vendor wallets credited, and external fulfillment dispatched.
        $owner = $this->approvedVendor();
        $product = $this->product($owner, 88.00);

        $this->postJson(route('purchase'), [
            'recipient_phone_number' => '0596621893',
            'vendor_id' => $owner->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
        ])->assertStatus(403);

        $this->assertSame(0, Order::where('payment_status', 'paid')->count());
        $this->assertSame(0, Transaction::count());
        $this->assertEqualsWithDelta(0.0, (float) $owner->fresh()->wallet_balance, 0.001);
    }

    public function test_a_valid_vendor_wallet_purchase_still_works(): void
    {
        $owner = $this->approvedVendor();
        $buyer = $this->approvedVendor();
        $product = $this->product($owner, 10.00);

        // Fund the buyer's wallet through the same topup ledger the debit reads.
        \App\Models\WalletTopup::create([
            'vendor_id' => $buyer->id,
            'amount' => 100.00,
            'remaining_amount' => 100.00,
            'reference' => 'TOPUP-1',
            'status' => 'completed',
        ]);

        $this->actingAs($buyer, 'vendor');
        $response = $this->postJson(route('purchase'), [
            'recipient_phone_number' => '0596621893',
            'vendor_id' => $owner->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(PaymentIntegrity::WALLET_VERIFIED, $order->payment_integrity_status);
        // base 10.00 + 2% platform fee = 10.20 charged, and frozen as such.
        $this->assertSame('10.20', (string) $order->expected_amount);
        $this->assertSame('10.20', (string) $order->amount_paid);
        $this->assertTrue($order->allowsFulfillment());
    }
}
