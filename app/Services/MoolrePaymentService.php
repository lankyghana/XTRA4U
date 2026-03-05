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

    protected function apiKey(): string
    {
        $apiKey = trim((string) $this->config->getConfig('api_key', ''));

        if ($apiKey !== '') {
            return $apiKey;
        }

        // Backward-compatibility fallback for legacy configs that only stored public_key.
        return $this->publicKey();
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

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($digits, '0')) {
            $digits = '233' . substr($digits, 1);
        }

        if (!str_starts_with($digits, '233')) {
            $digits = '233' . $digits;
        }

        return $digits;
    }

    protected function mapCollectionChannel(?string $network): ?string
    {
        $value = strtoupper(trim((string) $network));

        return match ($value) {
            '13', 'MTN' => '13',
            '6', 'TELECEL', 'VODAFONE' => '6',
            '7', 'AIRTELTIGO', 'AIRTEL_TIGO', 'AT' => '7',
            default => null,
        };
    }

    protected function normalizePaymentStatus(array $rawData): string
    {
        $txStatus = data_get($rawData, 'txstatus');

        if (is_numeric($txStatus)) {
            return match ((int) $txStatus) {
                1 => 'success',
                0 => 'pending',
                2 => 'failed',
                default => 'unknown',
            };
        }

        $status = strtolower((string) (data_get($rawData, 'status') ?? ''));
        return match ($status) {
            'success', 'successful', 'paid', 'completed' => 'success',
            'pending', 'processing', 'initiated' => 'pending',
            'failed', 'error', 'cancelled', 'canceled' => 'failed',
            default => 'unknown',
        };
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

        $externalRef = (string) ($order->payment_reference ?: ('XTRA4U-MLR-' . strtoupper(uniqid()) . '-' . $order->id));
        $payer = $this->normalizePhone((string) ($order->mobile_money_number ?? ''));
        $channel = $this->mapCollectionChannel((string) ($order->mobile_money_network ?? ''));

        if ($payer === '' || $channel === null) {
            Log::warning('Moolre payment init blocked: missing payer details', [
                'order_id' => $order->id,
                'reference' => $externalRef,
                'has_payer' => $payer !== '',
                'network' => (string) ($order->mobile_money_network ?? ''),
            ]);

            return [
                'success' => false,
                'message' => 'Missing payer phone/network for Moolre MoMo prompt.',
                'reference' => $externalRef,
            ];
        }

        $payload = [
            'type' => 1,
            'amount' => (string) $amount,
            'currency' => $this->currency(),
            'payer' => $payer,
            'channel' => $channel,
            'externalref' => $externalRef,
            'narration' => 'Order payment',
            'callbackurl' => route('webhooks.moolre.payment'),
            'accountnumber' => $this->accountNumber(),
        ];

        Log::info('Moolre payment initiation request', [
            'order_id' => $order->id,
            'reference' => $externalRef,
            'amount' => (string) $amount,
            'currency' => $this->currency(),
            'channel' => $channel,
            'payer' => $payer,
        ]);

        try {
            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/payment', $payload);

            $data = $response->json();
            $apiStatus = (int) ($data['status'] ?? 0);

            if ($response->successful() && $apiStatus === 1) {
                $order->update([
                    'payment_reference' => $externalRef,
                    'payment_status' => 'pending',
                    'payment_gateway' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                ]);

                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Waiting for MoMo confirmation... Check your phone and enter your PIN.',
                    'reference' => $externalRef,
                    'authorization_url' => null,
                    'transaction_id' => data_get($data, 'data.transactionid'),
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                    'flow_type' => 'inline',
                ];
            }

            Log::warning('Moolre payment initiation failed', [
                'order_id' => $order->id,
                'reference' => $externalRef,
                'amount' => (string) $amount,
                'currency' => $this->currency(),
                'channel' => $channel,
                'http_status' => $response->status(),
                'response' => $data,
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initiate MoMo payment.',
                'reference' => $externalRef,
            ];
        } catch (\Throwable $e) {
            Log::error('Moolre payment exception', [
                'order_id' => $order->id,
                'reference' => $externalRef,
                'amount' => (string) $amount,
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
        $payer = $this->normalizePhone((string) (
            $metadata['phone_number']
                ?? $metadata['payer']
                ?? $metadata['payer_phone']
                ?? $metadata['mobile_money_number']
                ?? ''
        ));
        $channel = $this->mapCollectionChannel((string) (
            $metadata['channel']
                ?? $metadata['network']
                ?? $metadata['payer_network']
                ?? ''
        ));

        if ($payer === '' || $channel === null) {
            return [
                'success' => false,
                'message' => 'Missing payer phone/network for Moolre inline collection.',
                'reference' => $externalRef,
            ];
        }

        $payload = [
            'type' => 1,
            'amount' => (string) $amount,
            'currency' => $this->currency(),
            'payer' => $payer,
            'channel' => $channel,
            'externalref' => $externalRef,
            'narration' => (string) ($metadata['narration'] ?? 'Inline payment collection'),
            'callbackurl' => route('webhooks.moolre.payment'),
            'accountnumber' => $this->accountNumber(),
        ];

        Log::info('Moolre generic payment initiation request', [
            'reference' => $externalRef,
            'amount' => (string) $amount,
            'currency' => $this->currency(),
            'channel' => $channel,
            'payer' => $payer,
            'source_callback' => $callbackUrl,
        ]);

        try {
            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/payment', $payload);

            $data = $response->json();

            if ($response->successful() && (int) ($data['status'] ?? 0) === 1) {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Waiting for MoMo confirmation... Check your phone and enter your PIN.',
                    'reference' => $externalRef,
                    'authorization_url' => null,
                    'transaction_id' => data_get($data, 'data.transactionid'),
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                    'flow_type' => 'inline',
                ];
            }

            Log::warning('Moolre generic payment init failed', [
                'payload' => $payload,
                'http_status' => $response->status(),
                'response' => $data,
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize MoMo payment.',
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
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/status', $payload);

            $data = $response->json();
            $apiStatus = (int) ($data['status'] ?? 0);

            if (!$response->successful() || $apiStatus !== 1) {
                $apiKey = $this->apiKey();
                Log::warning('Moolre verify failed', [
                    'reference' => $reference,
                    'base_url' => $this->baseUrl(),
                    'api_user' => $this->apiUser(),
                    'api_key_len' => strlen($apiKey),
                    'api_key_last4' => $apiKey !== '' ? substr($apiKey, -4) : null,
                    'http_status' => $response->status(),
                    'response' => $data,
                ]);

                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Failed to verify payment.',
                ];
            }

            $normalized = $this->normalizePaymentStatus((array) ($data['data'] ?? []));

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
