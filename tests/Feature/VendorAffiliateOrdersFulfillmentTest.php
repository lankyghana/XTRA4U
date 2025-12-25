<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorAffiliateOrdersFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_affiliate_order_in_orders_only_and_cannot_update_status(): void
    {
        $owner = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $reseller = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0551111111',
            'mobile_money_number' => '0551111111',
            'service_purchased' => 'AFFILIATE-PRODUCT',
            'amount_paid' => 100.00,
            // Selling vendor
            'vendor_id' => $reseller->id,
            'reseller_vendor_id' => $reseller->id,
            // Product owner
            'owner_vendor_id' => $owner->id,
            'is_reseller_order' => true,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
            'base_price' => 80.00,
            'markup_price' => 20.00,
            'owner_earning' => 78.40,
            'reseller_earning' => 19.60,
            'platform_commission' => 2.00,
        ]);

        // Successful transactions exist for the order, so it is visible in vendor views.
        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $owner->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 80.00,
            'commission_amount' => 1.60,
            'vendor_earning' => 78.40,
            'payment_status' => 'successful',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $reseller->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 20.00,
            'commission_amount' => 0.40,
            'vendor_earning' => 19.60,
            'payment_status' => 'successful',
        ]);

        $this->actingAs($owner, 'vendor');

        // Owner should see the order under My Orders.
        $ordersResponse = $this->get('/vendor/orders');
        $ordersResponse->assertStatus(200);
        $ordersResponse->assertSeeText('#' . $order->id);

        // Owner should NOT see it under Affiliate Orders tab.
        $affiliateResponse = $this->get('/vendor/orders/affiliate');
        $affiliateResponse->assertStatus(200);
        $affiliateResponse->assertDontSeeText('#' . $order->id);

        // Owner must not be able to update affiliate order status.
        $updateResponse = $this->patch(route('vendor.orders.update-status', $order), [
            'status' => 'Completed',
        ]);
		$updateResponse->assertRedirect(route('vendor.orders.index'));
		$updateResponse->assertSessionHas('error');
    }

    public function test_reseller_sees_affiliate_order_in_both_lists_and_can_update_status(): void
    {
        $owner = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $reseller = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0552222222',
            'mobile_money_number' => '0552222222',
            'service_purchased' => 'AFFILIATE-PRODUCT-2',
            'amount_paid' => 50.00,
            // Selling vendor
            'vendor_id' => $reseller->id,
            'reseller_vendor_id' => $reseller->id,
            // Product owner
            'owner_vendor_id' => $owner->id,
            'is_reseller_order' => true,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
            'base_price' => 40.00,
            'markup_price' => 10.00,
            'owner_earning' => 39.20,
            'reseller_earning' => 9.80,
            'platform_commission' => 1.00,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $reseller->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 10.00,
            'commission_amount' => 0.20,
            'vendor_earning' => 9.80,
            'payment_status' => 'successful',
        ]);

        $this->actingAs($reseller, 'vendor');

        $ordersResponse = $this->get('/vendor/orders');
        $ordersResponse->assertStatus(200);
        $ordersResponse->assertSeeText('#' . $order->id);

        $affiliateResponse = $this->get('/vendor/orders/affiliate');
        $affiliateResponse->assertStatus(200);
        $affiliateResponse->assertSeeText('#' . $order->id);

        $updateResponse = $this->patch(route('vendor.orders.update-status', $order), [
            'status' => 'Completed',
        ]);
        $updateResponse->assertStatus(302);

        $this->assertSame('Completed', $order->fresh()->status);

        Transaction::where('order_id', $order->id)->get()->each(function ($t) {
            $this->assertSame('completed', $t->payment_status);
        });
    }
}
