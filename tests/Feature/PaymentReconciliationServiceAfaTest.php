<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationServiceAfaTest extends TestCase
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

    protected function pendingRegistration(string $reference, float $amount = 10.00, string $gateway = 'paystack'): AfaRegistration
    {
        $vendor = Vendor::factory()->create(['afa_enabled' => true, 'afa_price' => $amount]);

        return AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'full_name' => 'Test User',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0550000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Engineer',
            'amount' => $amount,
            'vendor_price' => $amount,
            'platform_commission' => round($amount * 0.02, 2),
            'vendor_earning' => round($amount * 0.98, 2),
            'reseller_earning' => 0,
            'is_reseller_order' => false,
            'status' => AfaRegistration::STATUS_PENDING,
            'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'reference' => AfaRegistration::generateReference(),
            'payment_reference' => $reference,
            'payment_gateway' => $gateway,
        ]);
    }

    protected function service(): PaymentReconciliationService
    {
        return app(PaymentReconciliationService::class);
    }

    // A. SUCCESS
    public function test_success_completes_exactly_once(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-A', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 1000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($registration);

        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->fresh()->payment_status);
    }

    // B. FAILED
    public function test_authoritative_failure_cancels(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-B', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 1000],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($registration);

        $this->assertSame(PaymentReconciliationService::OUTCOME_CANCELLED, $outcome);
        $registration->refresh();
        $this->assertSame(AfaRegistration::PAYMENT_FAILED, $registration->payment_status);
        $this->assertSame(AfaRegistration::STATUS_CANCELLED, $registration->status);
    }

    // C. PENDING
    public function test_provider_pending_stays_pending(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-C', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'pending'],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($registration);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $registration->fresh()->payment_status);
    }

    // D. UNKNOWN / network error
    public function test_network_error_stays_unresolved(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-D', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $outcome = $this->service()->reconcile($registration);

        $this->assertSame(PaymentReconciliationService::OUTCOME_LEFT_PENDING, $outcome);
        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $registration->fresh()->payment_status);
    }

    // E. ORIGINAL GATEWAY after default changes
    public function test_original_gateway_is_queried_after_default_changes(): void
    {
        $this->makePaystackConfig(default: true);
        $payaza = $this->makePayazaConfig(default: false);
        $registration = $this->pendingRegistration('AFA-E', 10.00, 'paystack');

        $payaza->setAsDefault();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 1000],
            ], 200),
            'https://api.payaza.africa/*' => Http::response('should never be called', 500),
        ]);

        $outcome = $this->service()->reconcile($registration);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payaza'));
        $this->assertSame(PaymentReconciliationService::OUTCOME_COMPLETED, $outcome);
    }

    // F. DUPLICATE RECONCILIATION
    public function test_duplicate_reconciliation_has_exactly_one_financial_effect(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-F', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 1000],
            ], 200),
        ]);

        $this->service()->reconcile($registration);
        $vendor = $registration->fresh()->vendor;
        $balanceAfterFirst = (float) $vendor->wallet_balance;

        $this->service()->reconcile($registration->fresh());

        $vendor->refresh();
        $this->assertEquals($balanceAfterFirst, (float) $vendor->wallet_balance);
    }

    // G. WEBHOOK RACE
    public function test_webhook_completing_first_is_not_clobbered(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-G', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'failed', 'amount' => 1000],
            ], 200),
        ]);

        app(\App\Services\AfaPaymentService::class)->completeRegistration($registration->fresh());
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->fresh()->payment_status);

        $this->service()->reconcile($registration);

        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $registration->fresh()->payment_status);
    }

    // H. INTEGRITY MISMATCH
    public function test_success_with_wrong_amount_does_not_fulfil(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-H', 10.00);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true, 'data' => ['status' => 'success', 'amount' => 100],
            ], 200),
        ]);

        $outcome = $this->service()->reconcile($registration);

        $this->assertSame(PaymentReconciliationService::OUTCOME_INTEGRITY_MISMATCH, $outcome);
        $this->assertSame(AfaRegistration::PAYMENT_PENDING, $registration->fresh()->payment_status);
    }

    public function test_no_recorded_gateway_is_parked_without_any_gateway_call(): void
    {
        $this->makePaystackConfig();
        $registration = $this->pendingRegistration('AFA-NO-GW', 10.00);
        DB::table('afa_registrations')->where('id', $registration->id)->update(['payment_gateway' => null]);

        Http::fake();

        $outcome = $this->service()->reconcile($registration->fresh());

        Http::assertNothingSent();
        $this->assertSame(PaymentReconciliationService::OUTCOME_NO_GATEWAY, $outcome);
    }
}
