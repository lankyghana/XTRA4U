<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 2 payment reconciliation hardening — `payments:cleanup` safety
 * regression coverage.
 *
 * The core guarantee under test throughout: age alone is never evidence of
 * payment failure. The command must verify against the record's own stored
 * gateway before ever cancelling anything, and must never cancel a record
 * that something else (a webhook, in particular) has already resolved.
 */
class PaymentsCleanupReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function makePaystackConfig(bool $default = true): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    protected function makePayazaConfig(bool $default = false): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => $default,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ]);
    }

    protected function oldOrder(Vendor $vendor, Product $product, string $reference, string $gateway = 'paystack'): Order
    {
        $order = Order::create([
            'recipient_phone_number' => '0244000000',
            'mobile_money_number' => '0244000000',
            'service_purchased' => $product->name,
            'amount_paid' => $product->price,
            // Immutable financial terms, as every creation path now freezes them.
            'expected_amount' => $product->price,
            'currency' => 'GHS',
            'pricing_snapshot_at' => now(),
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => $gateway,
            'payment_reference' => $reference,
        ]);

        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(25)]);

        return $order->fresh();
    }

    protected function oldAfaRegistration(Vendor $vendor, string $reference, string $gateway = 'paystack'): AfaRegistration
    {
        $registration = AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'full_name' => 'Test User',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
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
            'status' => AfaRegistration::STATUS_PENDING,
            'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'reference' => AfaRegistration::generateReference(),
            'payment_reference' => $reference,
            'payment_gateway' => $gateway,
        ]);

        DB::table('afa_registrations')->where('id', $registration->id)->update(['created_at' => now()->subHours(25)]);

        return $registration->fresh();
    }

    protected function product(Vendor $vendor, float $price = 20.00): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data']),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // 1 & 2: uncertainty (network error / provider pending) -> stays pending
    // -----------------------------------------------------------------

    public function test_old_pending_order_with_network_timeout_remains_pending(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor), 'CLEANUP-TIMEOUT');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('Pending', $order->status);
    }

    public function test_old_pending_order_with_provider_pending_status_remains_pending(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor), 'CLEANUP-PENDING');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('Pending', $order->status);
    }

    // -----------------------------------------------------------------
    // 3: confirmed success -> completes safely, never cancelled
    // -----------------------------------------------------------------

    public function test_old_pending_order_with_confirmed_success_completes_instead_of_being_cancelled(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $product = $this->product($vendor, 20.00);
        $order = $this->oldOrder($vendor, $product, 'CLEANUP-SUCCESS');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotSame('Cancelled', $order->status);
        $this->assertNotSame('Failed', $order->status);

        $vendor->refresh();
        $this->assertGreaterThan(0, (float) $vendor->wallet_balance, 'Vendor should have been credited by the reused completion pipeline.');
    }

    // -----------------------------------------------------------------
    // 4: explicit provider failure -> eligible for cancellation
    // -----------------------------------------------------------------

    public function test_old_pending_order_with_explicit_provider_failure_is_cancelled(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor), 'CLEANUP-FAILED');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('Failed', $order->status);
    }

    // -----------------------------------------------------------------
    // 5: same scenarios for AfaRegistration
    // -----------------------------------------------------------------

    public function test_old_pending_afa_registration_with_confirmed_success_completes(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => 10]);
        $registration = $this->oldAfaRegistration($vendor, 'CLEANUP-AFA-SUCCESS');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 1000],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $registration->refresh();
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->payment_status);
        $this->assertNotSame(AfaRegistration::STATUS_CANCELLED, $registration->status);
    }

    public function test_old_pending_afa_registration_with_explicit_failure_is_cancelled(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $registration = $this->oldAfaRegistration($vendor, 'CLEANUP-AFA-FAILED');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'amount' => 10],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $registration->refresh();
        $this->assertSame(AfaRegistration::PAYMENT_FAILED, $registration->payment_status);
        $this->assertSame(AfaRegistration::STATUS_CANCELLED, $registration->status);
    }

    public function test_old_pending_afa_registration_with_network_timeout_remains_pending(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $registration = $this->oldAfaRegistration($vendor, 'CLEANUP-AFA-TIMEOUT');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $registration->refresh();
        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $registration->payment_status);
        $this->assertSame(AfaRegistration::STATUS_PENDING, $registration->status);
    }

    // -----------------------------------------------------------------
    // Category E: no gateway on record at all -> manual review, never guessed
    // -----------------------------------------------------------------

    public function test_old_pending_order_with_no_recorded_gateway_is_left_pending_for_manual_review(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor), 'CLEANUP-NO-GATEWAY', gateway: '');
        DB::table('orders')->where('id', $order->id)->update(['payment_gateway' => null]);

        // If the command guessed and queried the current default (Paystack),
        // this fake would answer "success" and the assertions below would
        // catch the order being wrongly completed.
        Http::fake([
            'https://api.paystack.co/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        Http::assertNothingSent();

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('Pending', $order->status);
    }

    // -----------------------------------------------------------------
    // 6: cleanup uses the stored original gateway, not the current default
    // -----------------------------------------------------------------

    public function test_cleanup_uses_stored_gateway_even_after_default_gateway_changed(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);

        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor, 20.00), 'CLEANUP-GATEWAY-SWITCH', 'paystack');

        // Admin switches the platform default to Payaza after the order was created.
        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 2000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payaza'));

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // 7 & 8: cleanup never initializes a charge or mints a new reference
    // -----------------------------------------------------------------

    public function test_cleanup_never_initializes_a_new_charge_or_reference(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor, 20.00), 'CLEANUP-NO-NEW-CHARGE');

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transaction/initialize'));

        $order->refresh();
        $this->assertSame('CLEANUP-NO-NEW-CHARGE', $order->payment_reference);
        $this->assertEquals(1, Order::where('vendor_id', $vendor->id)->count(), 'Cleanup must never create an additional order/charge.');
    }

    // -----------------------------------------------------------------
    // 9: a late webhook racing cleanup must not be clobbered
    // -----------------------------------------------------------------

    public function test_order_already_completed_by_a_webhook_is_not_overwritten_by_a_racing_cleanup_run(): void
    {
        $this->makePaystackConfig();
        $vendor = Vendor::factory()->create();
        $order = $this->oldOrder($vendor, $this->product($vendor, 20.00), 'CLEANUP-RACE');

        // Simulate a webhook having already completed the order moments
        // before cleanup's own (independent) gateway check runs. A webhook
        // only reaches completeOrder() once the payment integrity guard has
        // passed, so the order carries that proof here too.
        app(\App\Services\Payments\PaymentIntegrityGuard::class)->stampTrustedSource(
            $order,
            \App\Support\PaymentIntegrity::VERIFIED,
            'test fixture: webhook verified this payment first'
        );
        app(\App\Services\PaymentService::class)->completeOrder($order->fresh());
        $this->assertSame('paid', $order->fresh()->payment_status);

        // Cleanup's own verification call — even if it disagrees — must not
        // be allowed to flip an already-completed order back to failed.
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'amount' => 2000],
            ], 200),
        ]);

        $this->artisan('payments:cleanup')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status, 'A confirmed success must never be overwritten by a later failure verdict.');
        $this->assertNotSame('Failed', $order->status);
    }
}
