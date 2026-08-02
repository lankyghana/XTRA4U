<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_marking_order_refunded_deducts_vendor_wallet(): void
    {
        $this->actingAdmin();

        $vendor = Vendor::factory()->create(['wallet_balance' => 200.00]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 100.00,
            'vendor_id' => $vendor->id,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-100',
            'payment_gateway' => 'test',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 100.00,
            'commission_amount' => 2.00,
            'vendor_earning' => 98.00,
            'payment_status' => 'successful',
        ]);

        $response = $this->put(route('admin.orders.update', $order), [
            'status' => 'Refunded',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $vendor->refresh();

        $this->assertSame('Refunded', $order->status);
        $this->assertNotNull($order->wallet_reversed_at);
        $this->assertEquals(102.00, (float) $vendor->wallet_balance);

        $ledger = WalletLedger::where('vendor_id', $vendor->id)->where('type', 'debit')->first();
        $this->assertNotNull($ledger);
        $this->assertEquals(98.00, (float) $ledger->amount);
        $this->assertSame('order_refund', $ledger->source);

        $this->assertSame(1, VendorNotification::where('vendor_id', $vendor->id)
            ->where('type', VendorNotification::TYPE_ORDER_REFUNDED)
            ->count());
    }

    public function test_refunding_twice_does_not_double_deduct(): void
    {
        $this->actingAdmin();

        $vendor = Vendor::factory()->create(['wallet_balance' => 200.00]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 100.00,
            'vendor_id' => $vendor->id,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-101',
            'payment_gateway' => 'test',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 100.00,
            'commission_amount' => 2.00,
            'vendor_earning' => 98.00,
            'payment_status' => 'successful',
        ]);

        $this->put(route('admin.orders.update', $order), ['status' => 'Refunded']);

        // Admin flips it back, then refunds again.
        $this->put(route('admin.orders.update', $order), ['status' => 'Pending']);
        $this->put(route('admin.orders.update', $order), ['status' => 'Refunded']);

        $vendor->refresh();

        $this->assertEquals(102.00, (float) $vendor->wallet_balance);
        $this->assertSame(1, WalletLedger::where('vendor_id', $vendor->id)->where('type', 'debit')->count());
    }

    public function test_refund_is_blocked_when_vendor_balance_insufficient(): void
    {
        $this->actingAdmin();

        $vendor = Vendor::factory()->create(['wallet_balance' => 10.00]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 100.00,
            'vendor_id' => $vendor->id,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-102',
            'payment_gateway' => 'test',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 100.00,
            'commission_amount' => 2.00,
            'vendor_earning' => 98.00,
            'payment_status' => 'successful',
        ]);

        $response = $this->put(route('admin.orders.update', $order), [
            'status' => 'Refunded',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $order->refresh();
        $vendor->refresh();

        $this->assertSame('Completed', $order->status);
        $this->assertNull($order->wallet_reversed_at);
        $this->assertEquals(10.00, (float) $vendor->wallet_balance);
        $this->assertSame(0, WalletLedger::where('vendor_id', $vendor->id)->count());
    }

    public function test_refund_deducts_both_owner_and_reseller_wallets(): void
    {
        $this->actingAdmin();

        $owner = Vendor::factory()->create(['wallet_balance' => 200.00]);
        $reseller = Vendor::factory()->create(['wallet_balance' => 200.00]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 100.00,
            'vendor_id' => $reseller->id,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'is_reseller_order' => true,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-103',
            'payment_gateway' => 'test',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $owner->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 60.00,
            'commission_amount' => 1.20,
            'vendor_earning' => 58.80,
            'payment_status' => 'successful',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $reseller->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 40.00,
            'commission_amount' => 0.80,
            'vendor_earning' => 39.20,
            'payment_status' => 'successful',
        ]);

        $response = $this->put(route('admin.orders.update', $order), [
            'status' => 'Refunded',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $owner->refresh();
        $reseller->refresh();

        $this->assertEquals(141.20, (float) $owner->wallet_balance);
        $this->assertEquals(160.80, (float) $reseller->wallet_balance);
    }
}
