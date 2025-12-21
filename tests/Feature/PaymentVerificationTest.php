<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_does_not_credit_vendor_balance()
    {
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'business_name' => 'Test Vendor',
            'email' => 'test@vendor.com',
            'phone_number' => '0244000000',
            'password' => bcrypt('password'),
            'wallet_balance' => 0,
            'is_approved' => true,
        ]);

        $order = Order::create([
            'vendor_id' => $vendor->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-PENDING',
            'service_purchased' => 'Test Product',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $vendor->refresh();
        $this->assertEquals(0, $vendor->wallet_balance, 'Vendor balance should NOT be credited for pending payment');
    }

    public function test_successful_payment_credits_vendor_balance()
    {
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'business_name' => 'Test Vendor',
            'email' => 'test@vendor.com',
            'phone_number' => '0244000000',
            'password' => bcrypt('password'),
            'wallet_balance' => 0,
            'is_approved' => true,
        ]);

        $order = Order::create([
            'vendor_id' => $vendor->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-SUCCESS',
            'service_purchased' => 'Test Product',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        // Simulate webhook completing the payment
        $paymentService = app(PaymentService::class);
        $paymentService->completeOrder($order);

        $vendor->refresh();
        $this->assertEquals(98, $vendor->wallet_balance, 'Vendor balance should be credited after successful payment');
        
        $order->refresh();
        $this->assertEquals('completed', $order->payment_status);
        
        $transaction = Transaction::where('order_id', $order->id)->first();
        $this->assertEquals('successful', $transaction->status);
    }

    public function test_failed_payment_deletes_order()
    {
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'business_name' => 'Test Vendor',
            'email' => 'test@vendor.com',
            'phone_number' => '0244000000',
            'password' => bcrypt('password'),
            'wallet_balance' => 0,
            'is_approved' => true,
        ]);

        $order = Order::create([
            'vendor_id' => $vendor->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-FAILED',
            'service_purchased' => 'Test Product',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $orderId = $order->id;
        
        // Simulate webhook reporting failure
        $paymentService = app(PaymentService::class);
        $paymentService->markTransactionFailed($order, 'Gateway rejected');

        // Order should be deleted
        $this->assertDatabaseMissing('orders', ['id' => $orderId]);
        $this->assertDatabaseMissing('transactions', ['order_id' => $orderId]);
        
        $vendor->refresh();
        $this->assertEquals(0, $vendor->wallet_balance, 'Failed payment should NOT credit vendor');
    }

    public function test_idempotency_prevents_double_crediting()
    {
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'business_name' => 'Test Vendor',
            'email' => 'test@vendor.com',
            'phone_number' => '0244000000',
            'password' => bcrypt('password'),
            'wallet_balance' => 0,
            'is_approved' => true,
        ]);

        $order = Order::create([
            'vendor_id' => $vendor->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-IDEM',
            'service_purchased' => 'Test Product',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $paymentService = app(PaymentService::class);
        
        // Complete once
        $paymentService->completeOrder($order);
        $vendor->refresh();
        $this->assertEquals(98, $vendor->wallet_balance);

        // Try to complete again (duplicate webhook)
        $order->refresh();
        $paymentService->completeOrder($order);
        
        $vendor->refresh();
        $this->assertEquals(98, $vendor->wallet_balance, 'Balance should not be doubled on duplicate completion');
    }
}
