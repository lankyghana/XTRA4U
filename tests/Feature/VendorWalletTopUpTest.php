<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorWalletTopUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_topup_callback_credits_vendor_wallet_and_creates_ledger()
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        $reference = 'TEST-TOPUP-' . uniqid();

        // Mock PaymentService::checkPaymentStatus to return successful payload
        $this->mock(\App\Services\PaymentService::class, function ($mock) use ($reference, $vendor) {
            $mock->shouldReceive('checkPaymentStatus')->with($reference)->andReturn([
                'success' => true,
                'data' => [
                    'amount' => 100.00,
                    'metadata' => [
                        'vendor_id' => $vendor->id,
                        'purpose' => 'wallet_topup',
                    ],
                ],
            ]);
        });

        $response = $this->get(route('vendor.wallet.topup.callback', ['reference' => $reference]), [
            'Accept' => 'application/json',
        ]);
        $response->assertJson(['success' => true]);

        $vendor->refresh();
        $this->assertEquals(100.00, (float) $vendor->wallet_balance);

        $this->assertDatabaseHas('wallet_ledgers', [
            'vendor_id' => $vendor->id,
            'type' => 'credit',
            'amount' => 100.00,
        ]);
    }
}
