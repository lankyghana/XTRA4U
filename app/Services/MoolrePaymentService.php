<?php

namespace App\Services;

use App\Contracts\Gateways\CollectsPayments;
use App\Contracts\Gateways\HandlesGenericPayments;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoolrePaymentService implements CollectsPayments, HandlesGenericPayments
{
    protected PaymentGatewayConfig $config;

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config->getConfig('base_url', 'https://api.moolre.com'), '/');
    }

    protected function apiUser(): string
    {
        return trim((string) $this->config->getConfig('api_user', ''));
    }

    protected function publicKey(): string
    {
        return trim((string) $this->config->getConfig('public_key', ''));
    }

    protected function accountNumber(): string
    {
        return trim((string) $this->config->getConfig('account_number', ''));
    }

    protected function currency(): string
    {
        return trim((string) $this->config->getConfig('currency', 'GHS'));
    }

    protected function businessEmail(): string
    {
        return trim((string) $this->config->getConfig('business_email', ''));
    }

    protected function http(array $headers): PendingRequest
    {
        $client = Http::withHeaders($headers)
            // DNS + TCP connect can be slow/unreliable on some dev networks; give it room.
            ->connectTimeout(30)
            ->timeout(30)
            ->retry(2, 100);

        if (app()->environment('local', 'development', 'testing')) {
            // Prefer IPv4 in dev to avoid occasional IPv6 resolution stalls (common on some networks).
            $curlOptions = [];
            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }

            $client = $client->withOptions([
                'verify' => false,
                ...(empty($curlOptions) ? [] : ['curl' => $curlOptions]),
            ]);
        }

        return $client;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function requestPayment(Order $order, string $email, float $amount): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Moolre payment gateway not configured.',
                'reference' => null,
            ];
        }

        $externalRef = 'XTRA4U-MLR-' . uniqid() . '-' . $order->id;

        $payload = [
            'type' => 1,
            'amount' => (string) $amount,
            'email' => $this->businessEmail(),
            'externalref' => $externalRef,
            'callback' => route('webhooks.moolre.payment'),
            'redirect' => route('payment.callback'),
            'reusable' => '0',
            'currency' => $this->currency(),
            'accountnumber' => $this->accountNumber(),
            'metadata' => [
                'order_id' => $order->id,
                'source' => 'checkout',
            ],
        ];

        try {
            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-PUBKEY' => $this->publicKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/embed/link', $payload);

            $data = $response->json();

            if ($response->successful() && (int) ($data['status'] ?? 0) === 1) {
                $authorizationUrl = data_get($data, 'data.authorization_url');

                if ($authorizationUrl) {
                    // Persist for audit/debug and to support redirect-based callback.
                    $order->update([
                        'payment_reference' => $externalRef,
                        'payment_status' => 'pending',
                        'payment_gateway' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                    ]);

                    return [
                        'success' => true,
                        'message' => $data['message'] ?? 'Payment link generated.',
                        'reference' => $externalRef,
                        'authorization_url' => $authorizationUrl,
                    ];
                }
            }

            Log::warning('Moolre payment link generation failed', [
                'order_id' => $order->id,
                'payload' => $payload,
                'http_status' => $response->status(),
                'response' => $data,
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment.',
                'reference' => $externalRef,
            ];
        } catch (\Throwable $e) {
            Log::error('Moolre payment exception', [
                'order_id' => $order->id,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => app()->environment('local', 'development', 'testing')
                    ? ('Error initializing payment: ' . $e->getMessage())
                    : 'Error initializing payment.',
                'reference' => $externalRef,
            ];
        }
    }

    public function initiatePayment(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $metadata = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Moolre payment gateway not configured.',
                'reference' => $reference,
            ];
        }

        $externalRef = $reference ?? ('XTRA4U-MLR-' . strtoupper(uniqid()) . '-' . time());

        $payload = [
            'type' => 1,
            'amount' => (string) $amount,
            'email' => $this->businessEmail(),
            'externalref' => $externalRef,
            'callback' => route('webhooks.moolre.payment'),
            'redirect' => $callbackUrl,
            'reusable' => '0',
            'currency' => $this->currency(),
            'accountnumber' => $this->accountNumber(),
            'metadata' => $metadata,
        ];

        try {
            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-PUBKEY' => $this->publicKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/embed/link', $payload);

            $data = $response->json();

            if ($response->successful() && (int) ($data['status'] ?? 0) === 1) {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Payment link generated.',
                    'reference' => $externalRef,
                    'authorization_url' => data_get($data, 'data.authorization_url'),
                ];
            }

            Log::warning('Moolre generic payment init failed', [
                'payload' => $payload,
                'http_status' => $response->status(),
                'response' => $data,
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment.',
                'reference' => $externalRef,
            ];
        } catch (\Throwable $e) {
            Log::error('Moolre generic payment exception', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => app()->environment('local', 'development', 'testing')
                    ? ('Error initializing payment: ' . $e->getMessage())
                    : 'Error initializing payment.',
                'reference' => $externalRef,
            ];
        }
    }

    public function verifyPayment(string $reference): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Moolre payment gateway not configured.',
            ];
        }

        $payload = [
            'type' => 1,
            'idtype' => 1, // 1 = externalref
            'id' => $reference,
            'accountnumber' => $this->accountNumber(),
        ];

        try {
            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-PUBKEY' => $this->publicKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/status', $payload);

            $data = $response->json();

            if (!$response->successful() || (int) ($data['status'] ?? 0) !== 1) {
                $pubKey = $this->publicKey();
                Log::warning('Moolre verify failed', [
                    'reference' => $reference,
                    'payload' => $payload,
                    'base_url' => $this->baseUrl(),
                    'api_user' => $this->apiUser(),
                    'public_key_len' => strlen($pubKey),
                    'public_key_last4' => $pubKey !== '' ? substr($pubKey, -4) : null,
                    'http_status' => $response->status(),
                    'response' => $data,
                ]);

                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Failed to verify payment.',
                ];
            }

            $txStatus = (int) data_get($data, 'data.txstatus', 3);
            $normalized = match ($txStatus) {
                1 => 'success',
                0 => 'pending',
                2 => 'failed',
                default => 'unknown',
            };

            // Keep success=true here so downstream can decide based on status.
            return [
                'success' => true,
                'message' => $data['message'] ?? 'Payment status retrieved.',
                'data' => [
                    'status' => $normalized,
                    'amount' => (float) data_get($data, 'data.amount', 0),
                    'reference' => data_get($data, 'data.externalref', $reference),
                    'transactionid' => data_get($data, 'data.transactionid'),
                    'raw' => $data['data'] ?? [],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Moolre verify exception', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error verifying payment.',
            ];
        }
    }
}
