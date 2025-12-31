<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminConfirmPaymentTest extends TestCase
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

    public function test_admin_can_confirm_payment_for_unpaid_order(): void
    {
        Mail::fake();

        $this->actingAdmin();

        $vendor = Vendor::factory()->create([
            'wallet_balance' => 0,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 100.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_reference' => 'TEST-REF-001',
            'payment_gateway' => 'test',
        ]);

        // Simulate a placeholder transaction that an admin might have marked final prematurely.
        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 100.00,
            'commission_amount' => 0,
            'vendor_earning' => 0,
            'payment_status' => 'successful',
        ]);

        $transaction = Transaction::where('order_id', $order->id)
            ->where('vendor_id', $vendor->id)
            ->latest('id')
            ->firstOrFail();

        $response = $this->post(route('admin.transactions.confirm-payment', $transaction));

        $response->assertRedirect();

        $order->refresh();
        $vendor->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('Processing', $order->status);

        // 2% commission on 100 => vendor earning 98.
        $this->assertEquals(98.00, (float) $vendor->wallet_balance);

        $transaction = Transaction::where('order_id', $order->id)
            ->where('vendor_id', $vendor->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame('successful', $transaction->payment_status);
        $this->assertEquals(2.00, (float) $transaction->commission_amount);
        $this->assertEquals(98.00, (float) $transaction->vendor_earning);
    }

    public function test_confirm_payment_is_idempotent_when_order_already_paid(): void
    {
        Mail::fake();

        $this->actingAdmin();

        $vendor = Vendor::factory()->create([
            'wallet_balance' => 0,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 100.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-002',
            'payment_gateway' => 'test',
        ]);

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 100.00,
            'commission_amount' => 0,
            'vendor_earning' => 0,
            'payment_status' => 'pending',
        ]);

        $response = $this->post(route('admin.transactions.confirm-payment', $transaction));

        $response->assertRedirect();

        $order->refresh();
        $vendor->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(0.00, (float) $vendor->wallet_balance);
    }
}
