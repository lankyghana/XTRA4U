<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3 payment reconciliation — orchestration-level coverage for the
 * `payments:reconcile` command itself (eligibility selection, --type,
 * --reference manual mode, and the "never initializes a new charge/
 * reference" guarantee). The verify -> classify -> complete logic itself is
 * covered exhaustively in PaymentReconciliationService*Test — this file
 * only tests what the command adds on top: which records it picks up, and
 * when.
 */
class ReconcilePendingPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    protected function order(string $reference, float $price = 20.00, ?\Carbon\Carbon $createdAt = null): Order
    {
        $vendor = Vendor::factory()->create();
        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => $price,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => $reference,
        ]);

        if ($createdAt) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);
        }

        return $order->fresh();
    }

    // -----------------------------------------------------------------
    // Eligibility: too young, and not-yet-due are both skipped
    // -----------------------------------------------------------------

    public function test_a_record_younger_than_the_minimum_age_is_not_picked_up(): void
    {
        $this->makePaystackConfig();
        $order = $this->order('CMD-TOO-YOUNG', 20.00, now()); // just created

        Http::fake(); // any call fails the test

        $this->artisan('payments:reconcile')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_a_record_not_yet_due_for_its_next_attempt_is_skipped(): void
    {
        $this->makePaystackConfig();
        $order = $this->order('CMD-NOT-DUE', 20.00, now()->subHours(1));
        $order->forceFill([
            'reconciliation_attempts' => 1,
            'next_reconciliation_at' => now()->addHour(), // scheduled for later
        ])->save();

        Http::fake();

        $this->artisan('payments:reconcile')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_an_old_enough_never_checked_record_is_picked_up(): void
    {
        $this->makePaystackConfig();
        $order = $this->order('CMD-DUE', 20.00, now()->subMinutes(10));

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // --type
    // -----------------------------------------------------------------

    public function test_type_option_restricts_to_one_payable_type(): void
    {
        $this->makePaystackConfig();
        $order = $this->order('CMD-TYPE', 20.00, now()->subMinutes(10));

        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 10]);
        $afa = \App\Models\AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'full_name' => 'Test User',
            'id_type' => \App\Models\AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0550000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Engineer',
            'amount' => 10,
            'vendor_price' => 10,
            'platform_commission' => 0.2,
            'vendor_earning' => 9.8,
            'reseller_earning' => 0,
            'is_reseller_order' => false,
            'status' => \App\Models\AfaRegistration::STATUS_PENDING,
            'payment_status' => \App\Models\AfaRegistration::PAYMENT_PENDING,
            'reference' => \App\Models\AfaRegistration::generateReference(),
            'payment_reference' => 'CMD-TYPE-AFA',
            'payment_gateway' => 'paystack',
        ]);
        DB::table('afa_registrations')->where('id', $afa->id)->update(['created_at' => now()->subMinutes(10)]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:reconcile', ['--type' => 'order'])->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        // AFA was NOT touched — --type=order must not reach it.
        $this->assertSame(\App\Models\AfaRegistration::PAYMENT_PENDING, $afa->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // --reference (manual mode)
    // -----------------------------------------------------------------

    public function test_reference_option_reconciles_a_single_record_using_its_own_gateway(): void
    {
        $paystack = $this->makePaystackConfigReturning();
        $payaza = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'x', 'secret_key' => 'x', 'base_url' => 'https://api.payaza.africa/live'],
            'supported_features' => [],
        ]);

        // Record was created while Paystack was default; default has since switched.
        $order = $this->order('CMD-MANUAL', 20.00, now()->subMinutes(10));
        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $this->artisan('payments:reconcile', ['--reference' => 'CMD-MANUAL'])->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payaza'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_reference_option_reports_failure_for_unknown_reference(): void
    {
        $this->makePaystackConfig();

        $this->artisan('payments:reconcile', ['--reference' => 'NO-SUCH-REF'])->assertExitCode(1);
    }

    // -----------------------------------------------------------------
    // Never initializes a new charge or reference
    // -----------------------------------------------------------------

    public function test_reconciliation_run_never_hits_any_initialize_endpoint(): void
    {
        $this->makePaystackConfig();
        $order = $this->order('CMD-NO-INIT', 20.00, now()->subMinutes(10));

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/transaction/initialize'));
        $this->assertSame($order->payment_reference, $order->fresh()->payment_reference, 'The reference must never change.');
        $this->assertEquals(1, Order::count(), 'No new order/charge may be created by reconciliation.');
    }

    // -----------------------------------------------------------------
    // Phase 5: one broken payable cannot abort the rest of the batch
    // -----------------------------------------------------------------

    public function test_one_payable_throwing_does_not_abort_the_rest_of_the_batch(): void
    {
        $this->makePaystackConfig();
        $broken = $this->order('CMD-BROKEN', 20.00, now()->subMinutes(10));
        $healthy = $this->order('CMD-HEALTHY', 20.00, now()->subMinutes(10));

        $this->partialMock(PaymentReconciliationService::class, function ($mock) {
            $mock->shouldReceive('reconcile')->andReturnUsing(function ($payable) {
                if ($payable->payment_reference === 'CMD-BROKEN') {
                    throw new \RuntimeException('Simulated unexpected failure reconciling this one record.');
                }

                $payable->update(['payment_status' => 'paid']);

                return PaymentReconciliationService::OUTCOME_COMPLETED;
            });
        });

        $exitCode = $this->artisan('payments:reconcile')->run();

        // The command itself must still finish cleanly...
        $this->assertSame(0, $exitCode);
        // ...and the healthy record after the broken one in the batch must
        // still have been reached and processed.
        $this->assertSame('paid', $healthy->fresh()->payment_status);
        $this->assertSame('unpaid', $broken->fresh()->payment_status, 'The broken record is left exactly as it was, not corrupted.');
    }

    protected function makePaystackConfigReturning(): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }
}
