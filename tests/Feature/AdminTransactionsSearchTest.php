<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTransactionsSearchTest extends TestCase
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

    public function test_admin_transactions_can_be_searched_by_gateway_reference(): void
    {
        $this->actingAdmin();

        $vendor = Vendor::factory()->create([
            'name' => 'Acme Vendor',
        ]);

        $orderA = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-A',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_reference' => 'XTRA4U-REF-AAA',
        ]);

        $orderB = Order::create([
            'recipient_phone_number' => '0550000000',
            'mobile_money_number' => '0550000000',
            'service_purchased' => 'TEST-B',
            'amount_paid' => 12.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_reference' => 'XTRA4U-REF-BBB',
        ]);

        Transaction::create([
            'order_id' => $orderA->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $orderA->recipient_phone_number,
            'amount' => 10.00,
            'commission_amount' => 0,
            'vendor_earning' => 0,
            'payment_status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $orderB->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $orderB->recipient_phone_number,
            'amount' => 12.00,
            'commission_amount' => 0,
            'vendor_earning' => 0,
            'payment_status' => 'pending',
        ]);

        $response = $this->get(route('admin.transactions.index', ['q' => 'REF-AAA']));

        $response->assertOk();
        $response->assertSee('XTRA4U-REF-AAA');
        $response->assertDontSee('XTRA4U-REF-BBB');
    }
}
