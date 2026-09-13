<?php

namespace App\Services;

use App\Contracts\Gateways\CollectsPayments;
use App\Contracts\Gateways\HandlesGenericPayments;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Support\GhanaPhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Payaza Web Checkout SDK gateway.
 *
 * Unlike Paystack/Moolre, initiating a Payaza payment involves no server-to-
 * server "initialize" call: the Web Checkout SDK (`PayazaCheckout.setup(...)
 * .showPopup()`) runs entirely in the browser and talks to Payaza directly.
 * This service's job on the collection side is therefore just:
 *   1. requestPayment()/initiatePayment() — mint a unique reference, persist
 *      it, and hand back the config the frontend needs to launch the SDK.
 *   2. verifyPayment() — the one real HTTP call this class makes: an
 *      authenticated server-to-server status query against Payaza, which is
 *      the sole source of truth used to mark a payment successful (never the
 *      browser-side SDK callback).
 *
 * API contract (https://docs.payaza.africa):
 *   - Auth: `Authorization: Payaza <base64(public_key)>`, `X-TenantID: test|live`, `X-ProductID: app`
 *   - Status query: GET {base_url}/subsidiary/collections/v1/check-status?transaction_reference=..&country_code=GH
 *   - Webhook signature: `x-payaza-signature` = base64(HMAC-SHA512(raw_body, secret_key))
 */
class PayazaPaymentService implements CollectsPayments, HandlesGenericPayments
{
    protected PaymentGatewayConfig $config;

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config->getConfig('base_url', 'https://api.payaza.africa/live'), '/');
    }

    protected function publicKey(): string
    {
        return trim((string) $this->config->getConfig('public_key', ''));
    }

    protected function secretKey(): string
    {
        return trim((string) $this->config->getConfig('secret_key', ''));
    }

    protected function currency(): string
    {
        return trim((string) $this->config->getConfig('currency', 'GHS')) ?: 'GHS';
    }

    /**
     * Payaza's `connection_mode` / `X-TenantID` must match the credential
     * environment. We derive it from the gateway config's own `environment`
     * column (shared across all gateways) rather than a Payaza-specific
     * field, so there is exactly one place an admin sets Test vs Live.
     */
    protected function isLive(): bool
    {
        return $this->config->environment === PaymentGatewayConfig::ENV_LIVE;
    }

    protected function connectionMode(): string
    {
        return $this->isLive() ? 'Live' : 'Test';
    }

    protected function tenantId(): string
    {
        return $this->isLive() ? 'live' : 'test';
    }

    protected function authorizationHeader(): string
    {
        return 'Payaza '.base64_encode($this->publicKey());
    }

    protected function http(): PendingRequest
    {
        $client = Http::withHeaders([
            'Authorization' => $this->authorizationHeader(),
            'X-TenantID' => $this->tenantId(),
            'X-ProductID' => 'app',
            'Accept' => 'application/json',
        ])
            ->connectTimeout(15)
            ->timeout(20)
            ->retry(2, 200, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);

        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Unique, consistently-formatted reference for a payment attempt.
     * Format mirrors the other gateways' own conventions
     * (e.g. Paystack's `XTRA4U-{UNIQID}-{suffix}`) — there is no single
     * shared reference generator in this codebase; each gateway mints its own.
     */
    private function generateReference(?int $orderId = null): string
    {
        $suffix = $orderId !== null ? (string) $orderId : (string) time();

        return 'XTRA4U-PYZ-'.strtoupper(uniqid()).'-'.$suffix;
    }

    /**
     * Build the config object the frontend hands to `PayazaCheckout.setup()`.
     * Only the public key is ever included — never the secret key.
     */
    protected function buildCheckoutConfig(
        string $reference,
        float $amount,
        string $email,
        string $phoneInternational,
        string $firstName,
        string $lastName,
        array $additionalDetails = []
    ): array {
        return [
            'merchant_key' => $this->publicKey(),
            'connection_mode' => $this->connectionMode(),
            'checkout_amount' => $amount,
            'currency_code' => $this->currency(),
            'email_address' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone_number' => $phoneInternational,
            'transaction_reference' => $reference,
            'additional_details' => $additionalDetails,
        ];
    }

    /**
     * Split a display name into (first, last) for Payaza's required fields.
     * XTRA4U doesn't collect a customer name at checkout (only a delivery
     * phone number), so — mirroring the existing "reuse vendor email" pattern
     * already used for Paystack — we fall back to a generic customer label
     * rather than fabricate a real person's name.
     */
    protected function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['Customer', 'XTRA4U'];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? 'Customer'];
    }

    public function requestPayment(Order $order, string $email, float $amount): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Payaza payment gateway not configured.',
                'reference' => null,
            ];
        }

        $reference = $this->generateReference($order->id);

        $phone = GhanaPhoneNumber::toInternational((string) (
            $order->mobile_money_number ?: $order->recipient_phone_number
        ));

        if ($phone === '') {
            Log::warning('Payaza payment init blocked: invalid Ghana phone number', [
                'order_id' => $order->id,
                'reference' => $reference,
            ]);

            return [
                'success' => false,
                'message' => 'A valid Ghana phone number is required (e.g. 0244123456).',
                'reference' => $reference,
            ];
        }

        [$firstName, $lastName] = $this->splitName($order->vendor?->name);

        $checkoutConfig = $this->buildCheckoutConfig(
            $reference,
            $amount,
            $email,
            $phone,
            $firstName,
            $lastName,
            ['order_id' => $order->id, 'vendor_id' => $order->vendor_id]
        );

        $order->update([
            'payment_reference' => $reference,
            'payment_status' => 'pending',
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYAZA,
        ]);

        Log::info('Payaza payment initiated (client-side checkout)', [
            'order_id' => $order->id,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $this->currency(),
            'connection_mode' => $this->connectionMode(),
        ]);

        return [
            'success' => true,
            'message' => 'Payaza checkout ready.',
            'reference' => $reference,
            'authorization_url' => null,
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            // Distinct from the generic 'inline' gateways (Moolre/BulkClix):
            // those poll immediately after init, Payaza must first open its
            // own SDK popup. Frontend keys off this exact value.
            'flow_type' => 'payaza',
            'checkout_config' => $checkoutConfig,
        ];
    }

    public function initiatePayment(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $metadata = []): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Payaza payment gateway not configured.',
                'reference' => $reference,
            ];
        }

        $reference = $reference ?? $this->generateReference();

        $rawPhone = (string) (
            $metadata['phone_number']
                ?? $metadata['payer']
                ?? $metadata['payer_phone']
                ?? $metadata['mobile_money_number']
                ?? ''
        );
        $phone = GhanaPhoneNumber::toInternational($rawPhone);

        if ($phone === '') {
            return [
                'success' => false,
                'message' => 'A valid Ghana phone number is required (e.g. 0244123456).',
                'reference' => $reference,
            ];
        }

        [$firstName, $lastName] = $this->splitName($metadata['customer_name'] ?? null);

        $checkoutConfig = $this->buildCheckoutConfig(
            $reference,
            $amount,
            $email,
            $phone,
            $firstName,
            $lastName,
            array_filter([
                'registration_id' => $metadata['registration_id'] ?? null,
                'vendor_id' => $metadata['vendor_id'] ?? null,
            ], fn ($v) => $v !== null)
        );

        Log::info('Payaza generic payment initiated (client-side checkout)', [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $this->currency(),
            'connection_mode' => $this->connectionMode(),
            'source_callback' => $callbackUrl,
        ]);

        return [
            'success' => true,
            'message' => 'Payaza checkout ready.',
            'reference' => $reference,
            'authorization_url' => null,
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'flow_type' => 'payaza',
            'checkout_config' => $checkoutConfig,
        ];
    }

    protected function normalizeStatus(array $data): string
    {
        $responseCode = (string) ($data['response_code'] ?? '');
        $status = strtolower((string) ($data['transaction_status'] ?? ''));

        if ($responseCode === '00' || in_array($status, ['completed', 'funds received', 'success', 'successful'], true)) {
            return 'success';
        }

        if (in_array($responseCode, ['06', '96'], true) || in_array($status, ['failed', 'transaction failed', 'declined'], true)) {
            return 'failed';
        }

        if ($responseCode === '09' || in_array($status, ['initialized', 'pending'], true)) {
            return 'pending';
        }

        return 'unknown';
    }

    /**
     * Query Payaza's Transaction Status Query endpoint — the sole source of
     * truth for whether a payment succeeded. Never trust the SDK callback or
     * a raw webhook payload in place of this.
     */
    public function verifyPayment(string $reference): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Payaza payment gateway not configured.',
            ];
        }

        $cacheKey = 'payaza_verify:'.$reference;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['success'] ?? false) && data_get($cached, 'data.status') === 'success') {
            return $cached;
        }

        try {
            $response = $this->http()->get($this->baseUrl().'/subsidiary/collections/v1/check-status', [
                'transaction_reference' => $reference,
                'country_code' => 'GH',
            ]);

            $data = $response->json();

            if (! $response->successful() || ! is_array($data)) {
                Log::warning('Payaza verify failed', [
                    'reference' => $reference,
                    'http_status' => $response->status(),
                    'response' => $data,
                ]);

                return [
                    'success' => false,
                    'message' => is_array($data) ? ($data['response_message'] ?? 'Failed to verify payment.') : 'Failed to verify payment.',
                ];
            }

            $normalized = $this->normalizeStatus($data);

            $result = [
                'success' => true,
                'message' => $data['response_message'] ?? 'Payment status retrieved.',
                'data' => [
                    'status' => $normalized,
                    'amount' => (float) ($data['transaction_amount'] ?? 0),
                    'reference' => $data['transaction_reference'] ?? $reference,
                    'payaza_reference' => $data['payaza_reference'] ?? null,
                    'raw' => $data,
                ],
            ];

            if ($normalized === 'success') {
                // Short cache to absorb the callback + webhook + poll hitting
                // this endpoint for the same settled transaction in quick succession.
                Cache::put($cacheKey, $result, now()->addMinutes(15));
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Payaza verify exception', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => app()->environment('local', 'development', 'testing')
                    ? ('Error verifying payment: '.$e->getMessage())
                    : 'Error verifying payment.',
            ];
        }
    }
}
