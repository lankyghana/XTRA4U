<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelStaleWithdrawalsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_stale_withdrawals_marks_failed_and_refunds_once(): void
    {
        $vendor = Vendor::factory()->create([
            'wallet_balance' => 0,
        ]);

        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 50.00,
            'momo_number' => '0240000000',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WD-TEST-1',
            'payout_status' => null,
        ]);

        // Make it look stale
        $withdrawal->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        $this->artisan('withdrawals:cancel-stale --minutes=5 --limit=10')
            ->assertExitCode(0);

        $withdrawal->refresh();
        $vendor->refresh();

        $this->assertSame(VendorWithdrawal::STATUS_FAILED, $withdrawal->status);
        $this->assertSame('failed', $withdrawal->payout_status);
        $this->assertNotNull($withdrawal->refunded_at);
        $this->assertSame(50.00, (float) $vendor->wallet_balance);

        // Idempotent: running again should not double refund
        $this->artisan('withdrawals:cancel-stale --minutes=5 --limit=10')
            ->assertExitCode(0);

        $vendor->refresh();
        $this->assertSame(50.00, (float) $vendor->wallet_balance);
    }

    public function test_cancel_stale_withdrawals_skips_when_payout_reference_present(): void
    {
        $vendor = Vendor::factory()->create([
            'wallet_balance' => 0,
        ]);

        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 25.00,
            'momo_number' => '0240000000',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WD-TEST-2',
            'payout_reference' => 'PAYOUT-REF-123',
        ]);

        $withdrawal->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        $this->artisan('withdrawals:cancel-stale --minutes=5 --limit=10')
            ->assertExitCode(0);

        $withdrawal->refresh();
        $vendor->refresh();

        $this->assertSame(VendorWithdrawal::STATUS_PROCESSING, $withdrawal->status);
        $this->assertNull($withdrawal->refunded_at);
        $this->assertSame(0.00, (float) $vendor->wallet_balance);
    }

    public function test_cancel_stale_withdrawals_refuses_unsafe_flags_without_force(): void
    {
        $this->artisan('withdrawals:cancel-stale --all --limit=10')
            ->assertExitCode(1);

        $this->artisan('withdrawals:cancel-stale --include-referenced --limit=10')
            ->assertExitCode(1);
    }

    public function test_cancel_stale_withdrawals_can_force_cancel_referenced_when_forced(): void
    {
        $vendor = Vendor::factory()->create([
            'wallet_balance' => 0,
        ]);

        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 25.00,
            'momo_number' => '0240000000',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WD-TEST-3',
            'payout_reference' => 'PAYOUT-REF-123',
        ]);

        $withdrawal->forceFill([
            'created_at' => now()->subMinutes(1),
            'updated_at' => now()->subMinutes(1),
        ])->save();

        $this->artisan('withdrawals:cancel-stale --all --include-referenced --force --limit=10')
            ->assertExitCode(0);

        $withdrawal->refresh();
        $vendor->refresh();

        $this->assertSame(VendorWithdrawal::STATUS_FAILED, $withdrawal->status);
        $this->assertNotNull($withdrawal->refunded_at);
        $this->assertSame(25.00, (float) $vendor->wallet_balance);
    }
}
