<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the CSP fix that allows the Payaza Web Checkout SDK
 * (https://checkout-v2.payaza.africa/js/v1/bundle.js) to load without
 * weakening the policy for anything else.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected const PAYAZA_ORIGIN = 'https://checkout-v2.payaza.africa';

    protected function makeGatewayConfig(string $name, array $overrides = []): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create(array_merge([
            'gateway_name' => $name,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ], $overrides));
    }

    protected function cspDirective(string $csp, string $directive): ?string
    {
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if ($part === $directive || str_starts_with($part, $directive.' ')) {
                return $part;
            }
        }

        return null;
    }

    // 1. Payaza active -> script-src and frame-src allow the exact SDK origin.
    public function test_csp_allows_payaza_checkout_origin_when_payaza_is_active(): void
    {
        $this->makeGatewayConfig(PaymentGatewayConfig::GATEWAY_PAYAZA);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString(self::PAYAZA_ORIGIN, $this->cspDirective($csp, 'script-src'));
        $this->assertStringContainsString(self::PAYAZA_ORIGIN, $this->cspDirective($csp, 'frame-src'));
    }

    // 2. The origin is narrow — no wildcard payaza.africa, no '*' anywhere in script-src.
    public function test_csp_does_not_become_wildcard_permissive_for_payaza(): void
    {
        $this->makeGatewayConfig(PaymentGatewayConfig::GATEWAY_PAYAZA);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');
        $scriptSrc = $this->cspDirective($csp, 'script-src');
        $frameSrc = $this->cspDirective($csp, 'frame-src');

        $this->assertStringNotContainsString('*.payaza.africa', $scriptSrc);
        $this->assertStringNotContainsString('https://*', $scriptSrc);
        $this->assertStringNotContainsString("script-src '*'", $csp);
        $this->assertStringNotContainsString('*.payaza.africa', $frameSrc);
    }

    // 3. Payaza's SDK does not make direct browser fetch calls, so connect-src
    //    is left untouched (no over-broad grant beyond what's confirmed needed).
    public function test_csp_does_not_add_payaza_to_connect_src(): void
    {
        $this->makeGatewayConfig(PaymentGatewayConfig::GATEWAY_PAYAZA);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');
        $connectSrc = $this->cspDirective($csp, 'connect-src');

        $this->assertStringNotContainsString(self::PAYAZA_ORIGIN, $connectSrc);
    }

    // 4. Without an active Payaza gateway, the origin is absent entirely.
    public function test_csp_omits_payaza_origin_when_gateway_inactive(): void
    {
        $this->makeGatewayConfig(PaymentGatewayConfig::GATEWAY_PAYAZA, ['is_active' => false, 'is_default' => false]);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(self::PAYAZA_ORIGIN, $csp);
    }

    // 5. Existing gateways are unaffected: Paystack keeps its connect-src/frame-src grants.
    public function test_other_gateways_are_unaffected_by_the_payaza_change(): void
    {
        $this->makeGatewayConfig('paystack', ['is_default' => true]);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://checkout.paystack.com', $this->cspDirective($csp, 'connect-src'));
        $this->assertStringContainsString('https://checkout.paystack.com', $this->cspDirective($csp, 'frame-src'));
        $this->assertStringNotContainsString(self::PAYAZA_ORIGIN, $csp);
    }

    // 6. Other existing security headers remain present and unchanged.
    public function test_other_security_headers_remain_present(): void
    {
        $this->makeGatewayConfig(PaymentGatewayConfig::GATEWAY_PAYAZA);

        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    // 7. Production CSP does not lose local-dev-only restrictions leaking in
    //    (Vite/localhost origins stay confined to local/development envs).
    public function test_production_csp_excludes_local_dev_origins(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->makeGatewayConfig(PaymentGatewayConfig::GATEWAY_PAYAZA);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('localhost:5173', $csp);
        $this->assertStringNotContainsString('127.0.0.1:5173', $csp);
        $this->assertStringNotContainsString('localhost:8000', $csp);
        // Payaza's origin must still be present in production.
        $this->assertStringContainsString(self::PAYAZA_ORIGIN, $csp);

        $this->app->detectEnvironment(fn () => 'testing');
    }
}
