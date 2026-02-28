<?php

namespace App\Services;

use App\Contracts\Gateways\CollectsPayments;
use App\Contracts\Gateways\HandlesGenericPayments;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkClixPaymentService implements CollectsPayments, HandlesGenericPayments
{
    protected PaymentGatewayConfig $config;

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config->getConfig('base_url', 'https://api.bulkclix.com/api/v1'), '/');
    }

    protected function apiKey(): string
    {
        $fromConfig = trim((string) $this->config->getConfig('api_key', ''));

        return $fromConfig !== ''
            ? $fromConfig
            : trim((string) config('services.bulkclix.api_key', ''));
    }

    protected function http(): PendingRequest
    {
        $client = Http::timeout(30)->retry(2, 100);

        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * BulkClix MoMo collection expects a local Ghana number format (e.g. 0500000000).
     */
    protected function toLocalGhanaNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($digits, '233') && strlen($digits) > 3) {
            return '0' . substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            return $digits;
        }

        // If already 9 digits (e.g. 541000000) assume Ghana and prefix 0.
        if (strlen($digits) === 9) {
            return '0' . $digits;
        }

        return $digits;
    }

    /**
     * Best-effort Ghana MoMo network inference.
     * BulkClix expects: MTN, TELECEL, AIRTELTIGO
     */
    protected function guessBulkClixNetwork(string $phone): ?string
    {
        $local = $this->toLocalGhanaNumber($phone);
        $prefix = substr($local, 0, 3);

        // Ghana MoMo prefixes (best-effort; numbers can evolve over time).
        // MTN: 024/025/053/054/055/058/059
        // Telecel (Vodafone): 020/050
        // AirtelTigo: 026/027/056/057
        $mtn = ['024', '025', '053', '054', '055', '058', '059'];
        $telecel = ['020', '050'];
        $airteltigo = ['026', '027', '056', '057'];

        if (in_array($prefix, $mtn, true)) {
            return 'MTN';
        }

        if (in_array($prefix, $telecel, true)) {
            return 'TELECEL';
        }

        if (in_array($prefix, $airteltigo, true)) {
            return 'AIRTELTIGO';
        }

        return null;
    }

    protected function sanitizeReference(string $reference): string
    {
        $ref = trim($reference);
        $ref = preg_replace('/[^A-Za-z0-9\-_.]/', '-', $ref);
        $ref = preg_replace('/\-+/', '-', $ref);
        return trim((string) $ref, '-');
    }

    /**
     * Build a BulkClix-friendly transaction id with a hard limit of 36 chars.
     * Prefix is `XTRA4U-BCX-` (11 chars) so we keep the remainder for uniqueness.
     */
    protected function buildTransactionId(?string $seed = null, $orderId = null): string
    {
        $prefix = 'XTRA4U-BCX-';
        $max = 36;

        $base = '';
        if ($seed && is_string($seed) && trim($seed) !== '') {
            $base = $this->sanitizeReference($seed);
        } else {
            $base = strtoupper(uniqid());
        }

        if ($orderId !== null) {
            $base = $base . '-' . (string) $orderId;
        }

        $available = max(1, $max - strlen($prefix));
        $suffix = substr($base, 0, $available);

        return $prefix . $suffix;
    }

    public function initiatePayment(
        string $email,
        float $amount,
        string $callbackUrl,
        ?string $reference = null,
        array $metadata = []
    ): array {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'BulkClix payment gateway not configured.',
                'reference' => $reference,
            ];
        }

        $rawPhone = (string) (
            $metadata['phone_number']
            ?? $metadata['phone']
            ?? $metadata['customer_phone']
            ?? ''
        );

        $localPhone = $this->toLocalGhanaNumber($rawPhone);
        if ($localPhone === '') {
            return [
                'success' => false,
                'message' => 'Missing MoMo number for this payment.',
                'reference' => $reference,
            ];
        }

        $providedNetwork = strtoupper(trim((string) ($metadata['network'] ?? $metadata['payer_network'] ?? '')));
        $network = in_array($providedNetwork, ['MTN', 'TELECEL', 'AIRTELTIGO'], true)
            ? $providedNetwork
            : $this->guessBulkClixNetwork($localPhone);
        if (! $network) {
            return [
                'success' => false,
                'message' => 'Unable to determine MoMo network for this number. Please enter a valid Ghana MoMo number (e.g. MTN: 024/025/053/054/055/058/059, Telecel: 020/050, AirtelTigo: 026/027/056/057).',
                'reference' => $reference,
            ];
        }

        $transactionId = $this->buildTransactionId($reference);

        $callbackWithRef = $callbackUrl;
        $separator = str_contains($callbackWithRef, '?') ? '&' : '?';
        $callbackWithRef .= $separator . 'reference=' . urlencode($transactionId);

        $payload = [
            'amount' => (float) $amount,
            'phone_number' => $localPhone,
            'network' => $network,
            'transaction_id' => $transactionId,
            'callback_url' => $callbackWithRef,
            'reference' => 'XTRA4U',
        ];

        try {
            $response = $this->http()
                ->withHeaders([
                    'x-api-key' => $this->apiKey(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl() . '/payment-api/momopay', $payload);

            $json = $response->json();

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $json['message'] ?? 'Payment initiated. Please approve the MoMo prompt to complete.',
                    'reference' => $transactionId,
                    // Inline flow: do not redirect away; the frontend should poll a verify endpoint.
                    'authorization_url' => null,
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
                    'flow_type' => 'inline',
                    'transaction_id' => data_get($json, 'data.ext_transaction_id') ?: data_get($json, 'data.transaction_id'),
                    'data' => $json['data'] ?? null,
                ];
            }

            Log::warning('BulkClix generic MoMo collection failed', [
                'payload' => $payload,
                'http_status' => $response->status(),
                'response' => $json,
            ]);

            return [
                'success' => false,
                'message' => $json['message'] ?? 'Failed to initiate BulkClix payment.',
                'reference' => $transactionId,
                'http_status' => $response->status(),
                'errors' => $json['data'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('BulkClix generic MoMo collection exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => app()->environment('local', 'development', 'testing')
                    ? ('Error initializing BulkClix payment: ' . $e->getMessage())
                    : 'Error initializing payment.',
                'reference' => $transactionId,
            ];
        }
    }

    public function requestPayment(Order $order, string $email, float $amount): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'BulkClix payment gateway not configured.',
                'reference' => null,
            ];
        }

        $localPhone = $this->toLocalGhanaNumber((string) ($order->mobile_money_number ?? ''));
        if ($localPhone === '') {
            return [
                'success' => false,
                'message' => 'Missing MoMo number for this order.',
                'reference' => null,
            ];
        }

        $providedNetwork = strtoupper(trim((string) ($order->mobile_money_network ?? '')));
        $network = in_array($providedNetwork, ['MTN', 'TELECEL', 'AIRTELTIGO'], true)
            ? $providedNetwork
            : $this->guessBulkClixNetwork($localPhone);
        if (! $network) {
            return [
                'success' => false,
                'message' => 'Unable to determine MoMo network for this number. Please enter a valid Ghana MoMo number (e.g. MTN: 024/025/053/054/055/058/059, Telecel: 020/050, AirtelTigo: 026/027/056/057).',
                'reference' => null,
            ];
        }

        $transactionId = $this->buildTransactionId(null, $order->id);

        $payload = [
            'amount' => (float) $amount,
            'phone_number' => $localPhone,
            'network' => $network,
            'transaction_id' => $transactionId,
            // BulkClix posts payment status updates to this URL; we still verify via checkstatus.
            'callback_url' => route('payment.callback') . '?reference=' . urlencode($transactionId),
            // "reference" appears to be a label/brand in the docs; keep it stable.
            'reference' => 'XTRA4U',
        ];

        try {
            $response = $this->http()
                ->withHeaders([
                    'x-api-key' => $this->apiKey(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl() . '/payment-api/momopay', $payload);

            $json = $response->json();

            if ($response->successful()) {
                $order->update([
                    'payment_reference' => $transactionId,
                    'payment_status' => 'pending',
                    'payment_gateway' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
                ]);

                return [
                    'success' => true,
                    'message' => $json['message'] ?? 'Payment initiated. Please approve the MoMo prompt to complete.',
                        'reference' => $transactionId,
                        // Inline flow: do not redirect away; the frontend will poll /checkout/verify.
                        'authorization_url' => null,
                        'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
                        'flow_type' => 'inline',
                        // Helps us persist the provider transaction id early when available.
                        'transaction_id' => data_get($json, 'data.ext_transaction_id') ?: data_get($json, 'data.transaction_id'),
                        'data' => $json['data'] ?? null,
                ];
            }

            Log::warning('BulkClix MoMo collection failed', [
                'order_id' => $order->id,
                'payload' => $payload,
                'http_status' => $response->status(),
                'response' => $json,
            ]);

            return [
                'success' => false,
                'message' => $json['message'] ?? 'Failed to initiate BulkClix payment.',
                'reference' => $transactionId,
                'http_status' => $response->status(),
                'errors' => $json['data'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('BulkClix MoMo collection exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => app()->environment('local', 'development', 'testing')
                    ? ('Error initializing BulkClix payment: ' . $e->getMessage())
                    : 'Error initializing payment.',
                'reference' => $transactionId,
            ];
        }
    }

    public function verifyPayment(string $reference): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'BulkClix payment gateway not configured.',
            ];
        }

        try {
            $response = $this->http()
                ->withHeaders([
                    'x-api-key' => $this->apiKey(),
                    'Accept' => 'application/json',
                ])
                ->get($this->baseUrl() . '/payment-api/checkstatus/' . urlencode($reference));

            $json = $response->json();

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $json['message'] ?? 'Payment status fetched.',
                    'data' => $json['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => $json['message'] ?? 'Failed to verify payment.',
                'http_status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::error('BulkClix verify exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Error verifying payment.',
            ];
        }
    }
}
