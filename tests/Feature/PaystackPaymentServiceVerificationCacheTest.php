<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Services\PaystackPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5 fix — PaystackPaymentService::verifyPayment() used to cache ANY
 * authoritative gateway response for 15 minutes, gated only on the HTTP
 * call succeeding and Paystack's own top-level `status` flag being true —
 * not on the transaction actually being settled. A merely-'pending' (or
 * even a provider-confirmed 'failed') verification could therefore mask a
 * later genuine state change for up to 15 minutes, for every caller sharing
 * this cache key: checkout.verify polling, PaymentReconciliationService,
 * and CheckoutIntentGuard's own synchronous re-check.
 */
class PaystackPaymentServiceVerificationCacheTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PaystackPaymentService
    {
        $config = PaymentGatewayConfig::create([
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

        return new PaystackPaymentService($config);
    }

    public function test_a_pending_verification_is_never_cached_and_a_later_failure_is_seen_immediately(): void
    {
        $settled = false;
        Http::fake(function () use (&$settled) {
            return Http::response([
                'status' => true,
                'data' => ['status' => $settled ? 'failed' : 'pending'],
            ], 200);
        });

        $service = $this->service();

        $first = $service->verifyPayment('REF-CACHE-1');
        $this->assertSame('pending', $first['data']['status']);

        $settled = true;
        $second = $service->verifyPayment('REF-CACHE-1');

        // Must reflect the NEW gateway state, not a stale cached 'pending'.
        $this->assertSame('failed', $second['data']['status']);
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/verify/')));
    }

    public function test_a_provider_confirmed_failure_is_never_cached_either(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['status' => true, 'data' => ['status' => 'failed']], 200);
        });

        $service = $this->service();
        $service->verifyPayment('REF-CACHE-2');
        $service->verifyPayment('REF-CACHE-2');

        // Two independent gateway calls — a failed verification is never
        // served from cache (a customer could conceivably still be charged
        // moments later on some rail; only a settled success is safe to cache).
        $this->assertSame(2, $callCount);
    }

    public function test_a_settled_success_is_cached_so_a_repeated_verify_skips_the_gateway(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 2000]], 200);
        });

        $service = $this->service();
        $first = $service->verifyPayment('REF-CACHE-3');
        $second = $service->verifyPayment('REF-CACHE-3');

        $this->assertSame('success', $first['data']['status']);
        $this->assertSame('success', $second['data']['status']);
        $this->assertSame(1, $callCount, 'A genuinely settled success is safe to serve from cache.');
    }
}
