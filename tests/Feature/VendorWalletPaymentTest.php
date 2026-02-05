<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\Product;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorWalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_pay_for_order_with_wallet_and_order_is_processed()
    {
        $initialBalance = 200.00;
        $price = 150.00;

        $vendor = Vendor::factory()->create([
            'wallet_balance' => $initialBalance,
            'is_approved' => true,
        ]);

        WalletTopup::create([
            'vendor_id' => $vendor->id,
            'amount' => $initialBalance,
            'status' => 'completed',
            'reference' => 'TEST-TOPUP-' . uniqid(),
            'metadata' => ['source' => 'test'],
        ]);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'Test Service',
            'description' => 'desc',
            'price' => $price,
            'is_active' => true,
        ]);

        // Simulate an authenticated vendor dashboard session
        $this->actingAs($vendor, 'vendor');

        // Post purchase with pay_with_wallet flag (Quick Buy flow)
        $response = $this->post(route('purchase'), [
            'recipient_phone_number' => '055' . fake()->numerify('#######'),
            'mobile_money_number' => '055' . fake()->numerify('#######'),
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'pay_with_wallet' => 1,
        ]);

        $response->assertRedirect();

        $vendor->refresh();

        // Vendor dashboard wallet orders charge base price + 2% upfront.
        $platformFee = round($price * 0.02, 2);
        $walletCharge = round($price + $platformFee, 2);

        // Net expected wallet balance after: initial - (price + fee)
        $this->assertEquals($initialBalance - $walletCharge, (float) $vendor->wallet_balance);

        $this->assertDatabaseHas('orders', [
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'payment_source' => 'wallet',
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseHas('wallet_ledgers', [
            'vendor_id' => $vendor->id,
            'type' => 'debit',
            'amount' => $walletCharge,
        ]);
    }
}
