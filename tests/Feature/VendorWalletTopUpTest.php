<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorWalletTopUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_topup_callback_credits_vendor_wallet_and_creates_ledger()
    {
        $vendor = Vendor::factory()->create(['wallet_balance' => 0.00]);

        $reference = 'TEST-TOPUP-'.uniqid();

        // Since the payment reconciliation hardening, verification requires a
        // WalletTopup record with a stored payment_gateway — the callback no
        // longer guesses/creates one from gateway-provided metadata alone.
        WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendor->id,
            'amount' => 100.00,
            'status' => 'initiated',
            'payment_gateway' => 'paystack',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        // Mock PaymentService::checkPaymentStatusForGateway to return successful payload
        $this->mock(\App\Services\PaymentService::class, function ($mock) use ($reference, $vendor) {
            $mock->shouldReceive('checkPaymentStatusForGateway')->with($reference, 'paystack')->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'success',
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
