<?php

namespace Tests\Feature;

use App\Jobs\ProcessVendorWithdrawalPayout;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use App\Services\GatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VendorWithdrawalAutoPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveBulkclixPayoutGateway(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_collection' => false,
            'supports_generic' => false,
            'supports_payout' => true,
            'supports_sms' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'api_key' => 'test-api-key',
                'base_url' => 'https://api.bulkclix.com/api/v1',
                'sender_id' => 'XTRA4U',
            ],
            'supported_features' => [
                'mtn_momo' => true,
            ],
        ]);
    }

    public function test_vendor_withdrawal_request_deducts_wallet_and_dispatches_job(): void
    {
        Queue::fake();

        $this->seedActiveBulkclixPayoutGateway();

        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
            'wallet_balance' => 100.00,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->post(route('vendor.withdrawals.store'), [
            'withdraw_amount' => 50.00,
            'momo_number' => '0244123456',
            'momo_network' => 'MTN',
            'notes' => 'Test payout',
        ]);

        $response->assertRedirect();

        $vendor->refresh();
        $this->assertEquals(50.00, (float) $vendor->wallet_balance);

        $withdrawal = VendorWithdrawal::firstOrFail();
        $this->assertEquals(VendorWithdrawal::STATUS_PROCESSING, $withdrawal->status);

        Queue::assertPushed(ProcessVendorWithdrawalPayout::class);
    }

    public function test_payout_failure_marks_failed_and_refunds_wallet_idempotently(): void
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
            'wallet_balance' => 50.00,
        ]);

        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 50.00,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WD-TEST-FAIL',
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
            'payout_gateway' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
        ]);

        // Simulate that the wallet was already deducted at request time.
        Vendor::whereKey($vendor->id)->update(['wallet_balance' => 0.00]);

        $gatewayManager = Mockery::mock(GatewayManager::class);
        $gatewayManager->shouldReceive('payout')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Gateway error',
            ]);
        $this->app->instance(GatewayManager::class, $gatewayManager);

        $job = new ProcessVendorWithdrawalPayout($withdrawal->id);
        app()->call([$job, 'handle']);

        $withdrawal->refresh();
        $vendor->refresh();

        $this->assertEquals(VendorWithdrawal::STATUS_FAILED, $withdrawal->status);
        $this->assertEquals('failed', $withdrawal->payout_status);
        $this->assertEquals('Gateway error', $withdrawal->error_message);
        $this->assertNotNull($withdrawal->refunded_at);
        $this->assertEquals(50.00, (float) $vendor->wallet_balance);

        // Second run should not call gateway again and should not double-refund.
        app()->call([$job, 'handle']);
        $vendor->refresh();
        $this->assertEquals(50.00, (float) $vendor->wallet_balance);
    }

    public function test_payout_success_marks_approved_idempotently(): void
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
            'wallet_balance' => 0.00,
        ]);

        $withdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 10.00,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WD-TEST-SUCCESS',
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
            'payout_gateway' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
        ]);

        $gatewayManager = Mockery::mock(GatewayManager::class);
        $gatewayManager->shouldReceive('payout')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'OK',
                'reference' => 'PAYOUT-REF-1',
                'transaction_id' => 'TXN-1',
                'status' => 'pending',
            ]);
        $this->app->instance(GatewayManager::class, $gatewayManager);

        $job = new ProcessVendorWithdrawalPayout($withdrawal->id);
        app()->call([$job, 'handle']);

        $withdrawal->refresh();
        $this->assertEquals(VendorWithdrawal::STATUS_APPROVED, $withdrawal->status);
        $this->assertEquals('completed', $withdrawal->payout_status);
        $this->assertNotNull($withdrawal->paid_at);

        // Second run should be a no-op (idempotent)
        app()->call([$job, 'handle']);
        $withdrawal->refresh();
        $this->assertEquals(VendorWithdrawal::STATUS_APPROVED, $withdrawal->status);
    }
}
