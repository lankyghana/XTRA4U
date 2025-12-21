<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\Product;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $vendor;
    protected $product;
    protected $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->vendor = Vendor::factory()->create([
            'wallet_balance' => 0,
            'is_approved' => true,
        ]);
        
        $this->product = Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'Test Product',
            'description' => 'Test product for payment flow assertions',
            'price' => 100,
            'is_active' => true,
            'is_resellable' => true,
            'min_base_price' => 80,
        ]);

        $this->paymentService = app(PaymentService::class);
    }

    /** @test */
    public function test_pending_order_does_not_update_vendor_balance()
    {
        // Create order
        $order = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-REF-123',
            'service_purchased' => 'Test Service',
        ]);

        // Initiate payment (create pending transaction)
        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
            'payment_reference' => 'TEST-REF-123',
        ]);

        // Vendor balance should NOT be updated
        $this->vendor->refresh();
        $this->assertEquals(0, $this->vendor->wallet_balance);

        // Transaction should be pending
        $transaction = Transaction::where('order_id', $order->id)->first();
        $this->assertEquals(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertNull($transaction->verified_at);
    }

    /** @test */
    public function test_successful_payment_updates_vendor_balance()
    {
        // Create pending order
        $order = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-REF-456',
            'service_purchased' => 'Test Service',
        ]);

        // Create pending transaction
        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
            'payment_reference' => 'TEST-REF-456',
        ]);

        // Complete the order (simulate webhook success)
        $this->paymentService->completeOrder($order);

        // Verify vendor balance updated
        $this->vendor->refresh();
        $this->assertEquals(98, $this->vendor->wallet_balance);

        // Verify transaction marked as successful
        $transaction = Transaction::where('order_id', $order->id)->first();
        $this->assertEquals(Transaction::STATUS_SUCCESSFUL, $transaction->status);
        $this->assertNotNull($transaction->verified_at);

        // Verify order status updated
        $order->refresh();
        $this->assertEquals('completed', $order->payment_status);
        $this->assertEquals('Processing', $order->status);
    }

    /** @test */
    public function test_failed_payment_deletes_order_and_transaction()
    {
        // Create pending order
        $order = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-REF-789',
            'service_purchased' => 'Test Service',
        ]);

        // Create pending transaction
        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
            'payment_reference' => 'TEST-REF-789',
        ]);

        $orderId = $order->id;

        // Mark as failed (simulate webhook failure)
        $this->paymentService->markTransactionFailed($order, 'Payment gateway rejected');

        // Verify order deleted
        $this->assertDatabaseMissing('orders', ['id' => $orderId]);

        // Verify transaction deleted
        $this->assertDatabaseMissing('transactions', ['order_id' => $orderId]);

        // Verify vendor balance NOT updated
        $this->vendor->refresh();
        $this->assertEquals(0, $this->vendor->wallet_balance);
    }

    /** @test */
    public function test_idempotency_prevents_duplicate_completion()
    {
        // Create pending order
        $order = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'TEST-REF-IDEM',
            'service_purchased' => 'Test Service',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
            'payment_reference' => 'TEST-REF-IDEM',
        ]);

        // Complete order once
        $this->paymentService->completeOrder($order);

        $this->vendor->refresh();
        $balanceAfterFirst = $this->vendor->wallet_balance;
        $this->assertEquals(98, $balanceAfterFirst);

        // Try to complete again (simulate duplicate webhook)
        $order->refresh();
        $this->paymentService->completeOrder($order);

        // Verify balance NOT doubled
        $this->vendor->refresh();
        $this->assertEquals(98, $this->vendor->wallet_balance);
        $this->assertEquals($balanceAfterFirst, $this->vendor->wallet_balance);
    }

    /** @test */
    public function test_only_successful_transactions_show_in_vendor_queries()
    {
        // Create 3 orders with different statuses
        
        // 1. Successful
        $successOrder = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000001',
            'mobile_money_number' => '0244000001',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'SUCCESS-1',
            'service_purchased' => 'Test Service',
        ]);
        
        Transaction::create([
            'order_id' => $successOrder->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000001',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
        ]);
        
        $this->paymentService->completeOrder($successOrder);

        // 2. Pending (not completed)
        $pendingOrder = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000002',
            'mobile_money_number' => '0244000002',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'PENDING-1',
            'service_purchased' => 'Test Service',
        ]);
        
        Transaction::create([
            'order_id' => $pendingOrder->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000002',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
        ]);

        // Query for successful transactions only
        $successfulTransactions = Transaction::where('vendor_id', $this->vendor->id)
            ->where('status', Transaction::STATUS_SUCCESSFUL)
            ->get();

        // Should only return 1 (the successful one)
        $this->assertCount(1, $successfulTransactions);
        $this->assertEquals($successOrder->id, $successfulTransactions->first()->order_id);

        // Total vendor earnings should only include successful
        $totalEarnings = Transaction::where('vendor_id', $this->vendor->id)
            ->where('status', Transaction::STATUS_SUCCESSFUL)
            ->sum('vendor_earning');

        $this->assertEquals(98, $totalEarnings);
    }

    /** @test */
    public function test_abandoned_order_cleanup_deletes_old_pending_orders()
    {
        // Create old pending order (25 hours ago)
        $oldOrder = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'OLD-REF',
            'service_purchased' => 'Test Service',
        ]);

        $oldTransaction = Transaction::create([
            'order_id' => $oldOrder->id,
            'vendor_id' => $this->vendor->id,
            'recipient_phone' => '0244000000',
            'amount' => 100,
            'commission_amount' => 2,
            'vendor_earning' => 98,
            'payment_status' => 'pending',
            'status' => Transaction::STATUS_PENDING,
        ]);

        Order::whereKey($oldOrder->id)->update([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);

        Transaction::whereKey($oldTransaction->id)->update([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);

        // Create recent pending order (1 hour ago)
        $recentOrder = Order::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'recipient_phone_number' => '0244000001',
            'mobile_money_number' => '0244000001',
            'amount_paid' => 100,
            'payment_status' => 'pending',
            'status' => 'Pending',
            'payment_reference' => 'RECENT-REF',
            'service_purchased' => 'Test Service',
        ]);

        Order::whereKey($recentOrder->id)->update([
            'created_at' => now()->subHour(),
        ]);

        // Run cleanup command
        $this->artisan('orders:cleanup-pending')
            ->expectsOutput('Starting cleanup of pending orders...')
            ->assertExitCode(0);

        // Old order should be deleted
        $this->assertDatabaseMissing('orders', ['id' => $oldOrder->id]);

        // Recent order should still exist
        $this->assertDatabaseHas('orders', ['id' => $recentOrder->id]);
    }
}
