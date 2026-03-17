<?php

namespace App\Services\ExternalFulfillment\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Services\ExternalFulfillment\Contracts\ExternalFulfillmentClient;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XpresPortalClient implements ExternalFulfillmentClient
{
    public function __construct(private ExternalFulfillmentConfig $config)
    {
    }

    public function sendOrder(Order $order, string $idempotencyKey): array
    {
        $providerConfig = $this->config->providerConfig('xpresportal');
        $url = rtrim((string) ($providerConfig['base_url'] ?? ''), '/') . '/' . ltrim((string) ($providerConfig['endpoint'] ?? ''), '/');
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $apiSecret = (string) ($providerConfig['api_secret'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);
        $environment = (string) ($providerConfig['environment'] ?? 'sandbox');
        $allowedNetworks = (array) config('external_fulfillment.providers.xpresportal.networks', []);
        $serviceMapping = $this->resolveServiceMapping($order);

        $recipient = $this->normalizeMsisdn((string) $order->recipient_phone_number);
        if ($recipient === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Invalid recipient phone number for XpresPortal fulfillment.',
            ];
        }

        $productPayload = [
            'name' => $order->display_service_name,
            'description' => (string) $order->service_purchased,
        ];

        if (($serviceMapping['service_id'] ?? '') !== '') {
            $productPayload['service_id'] = (string) $serviceMapping['service_id'];
        }

        if (($serviceMapping['capacity'] ?? null) !== null && (string) $serviceMapping['capacity'] !== '') {
            $productPayload['capacity'] = $serviceMapping['capacity'];
        }

        $payload = [
            'reference' => (string) ($order->payment_reference ?? $idempotencyKey),
            'idempotency_key' => $idempotencyKey,
            'customer' => [
                'phone' => $recipient,
                'email' => (string) $order->customer_email,
            ],
            'network' => $this->resolveNetwork($order, $allowedNetworks),
            'amount' => (float) ($order->amount_paid ?? 0),
            'product' => $productPayload,
            'metadata' => array_filter([
                'order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'owner_vendor_id' => $order->owner_vendor_id,
                'reseller_vendor_id' => $order->reseller_vendor_id,
                'payment_gateway' => $order->payment_gateway,
                'mapped_service_id' => $serviceMapping['service_id'] ?? null,
                'mapped_service_name' => $serviceMapping['service_name'] ?? null,
            ], static fn ($value) => ! is_null($value) && $value !== ''),
            'environment' => $environment,
        ];

        if (($serviceMapping['service_id'] ?? '') !== '') {
            $payload['service_id'] = (string) $serviceMapping['service_id'];
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-API-KEY' => $apiKey,
                    'X-API-SECRET' => $apiSecret,
                    'X-ENV' => $environment,
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->post($url, $payload);

            $json = $response->json();
            $status = $this->normalizeStatus(data_get($json, 'status') ?? data_get($json, 'data.status') ?? null);
            $remoteReference = $this->extractReference($json);

            if ($response->successful() && in_array($status, ['success', 'completed'], true)) {
                return [
                    'success' => true,
                    'status' => 'success',
                    'external_reference' => $remoteReference,
                    'message' => 'success',
                    'raw' => $json,
                ];
            }

            if (in_array($status, ['pending', 'processing'], true)) {
                return [
                    'success' => false,
                    'status' => 'pending',
                    'external_reference' => $remoteReference,
                    'message' => 'pending',
                    'raw' => $json,
                ];
            }

            $errorMessage = $this->buildErrorMessage($response->status(), $json ?? $response->body());

            Log::warning('XpresPortal fulfillment failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'external_reference' => $remoteReference,
                'message' => $errorMessage,
                'raw' => $json,
            ];
        } catch (RequestException|ConnectionException $e) {
            $message = $this->limitMessage($e->getMessage());

            Log::error('XpresPortal fulfillment failed', [
                'order_id' => $order->id,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'external_reference' => null,
                'message' => $message,
                'raw' => null,
            ];
        }
    }

    public function getServices(): array
    {
        $providerConfig = $this->config->providerConfig('xpresportal');
        $baseUrl = trim((string) ($providerConfig['base_url'] ?? ''));
        $endpoint = trim((string) ($providerConfig['services_endpoint'] ?? '/api/v1/services'));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $apiSecret = (string) ($providerConfig['api_secret'] ?? '');
        $environment = (string) ($providerConfig['environment'] ?? 'sandbox');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);

        if ($baseUrl === '' || $endpoint === '' || $apiKey === '' || $apiSecret === '') {
            return [];
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-API-KEY' => $apiKey,
                    'X-API-SECRET' => $apiSecret,
                    'X-ENV' => $environment,
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('XpresPortal services fetch failed', [
                    'status' => $response->status(),
                    'provider' => 'xpresportal',
                ]);

                return [];
            }

            $json = $response->json();
            $services = data_get($json, 'services');

            if (! is_array($services)) {
                $services = data_get($json, 'data.services');
            }

            if (! is_array($services)) {
                $services = data_get($json, 'data');
            }

            if (! is_array($services)) {
                return [];
            }

            return $this->normalizeDiscoveredServices($services);
        } catch (RequestException|ConnectionException $e) {
            Log::warning('XpresPortal services fetch failed', [
                'provider' => 'xpresportal',
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [];
        }
    }

    private function normalizeMsisdn(string $msisdn): string
    {
        $digits = preg_replace('/[^0-9]/', '', $msisdn);

        if ($digits === '') return '';

        if (str_starts_with($digits, '233') && strlen($digits) > 3) {
            return '0' . substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return $digits;
        }

        if (strlen($digits) === 9) {
            return '0' . $digits;
        }

        return '';
    }

    private function normalizeStatus(mixed $status): string
    {
        $statusString = strtolower(trim((string) ($status ?? '')));

        return $statusString === '' ? 'failed' : $statusString;
    }

    private function extractReference(mixed $json): ?string
    {
        foreach (['reference', 'external_reference', 'id', 'data.reference', 'data.id'] as $path) {
            $value = data_get($json, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function buildErrorMessage(int $status, mixed $raw): string
    {
        $body = is_string($raw) ? $raw : json_encode($raw);
        $message = 'HTTP ' . $status;

        if (is_string($body) && $body !== '') {
            $message .= ': ' . mb_substr($body, 0, 500);
        }

        return $message;
    }

    private function limitMessage(string $message): string
    {
        return mb_substr($message, 0, 500);
    }

    private function resolveNetwork(Order $order, array $allowedNetworks): string
    {
        $preferred = $order->getAttribute('external_provider_network');
        $normalizedPreferred = $this->normalizeNetwork($preferred);
        if ($normalizedPreferred !== '' && (empty($allowedNetworks) || in_array($normalizedPreferred, $allowedNetworks, true))) {
            return $normalizedPreferred;
        }

        $metadata = [];
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $metadata = is_array($product?->decoded_description) ? $product->decoded_description : [];
        }

        $fromMappings = $this->normalizeNetwork(data_get($metadata, 'external_mappings.xpresportal.network'));
        if ($fromMappings !== '' && (empty($allowedNetworks) || in_array($fromMappings, $allowedNetworks, true))) {
            return $fromMappings;
        }

        $generic = $this->normalizeNetwork(data_get($metadata, 'external_network'));
        if ($generic !== '' && (empty($allowedNetworks) || in_array($generic, $allowedNetworks, true))) {
            return $generic;
        }

        $fallback = $this->normalizeNetwork($order->mobile_money_network);

        return $fallback !== '' ? $fallback : 'MTN';
    }

    private function normalizeNetwork(mixed $network): string
    {
        $string = is_string($network) ? $network : '';
        $trimmed = trim($string);

        return $trimmed === '' ? '' : strtoupper(preg_replace('/\s+/', '', $trimmed));
    }

    /** @return array{service_id:string,service_name:string,capacity:mixed,price:?float} */
    private function resolveServiceMapping(Order $order): array
    {
        $metadata = [];
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $metadata = is_array($product?->decoded_description) ? $product->decoded_description : [];
        }

        $serviceId = $this->firstString($metadata, [
            'external_mappings.xpresportal.service_id',
            'external_service_id',
        ]) ?? '';

        $serviceName = $this->firstString($metadata, [
            'external_mappings.xpresportal.service_name',
            'external_service_name',
        ]) ?? '';

        $capacity = data_get($metadata, 'external_mappings.xpresportal.capacity');
        if ($capacity === null || $capacity === '') {
            $capacity = data_get($metadata, 'size');
        }

        $price = null;
        $mappedPrice = data_get($metadata, 'external_mappings.xpresportal.price');
        if (is_numeric($mappedPrice)) {
            $price = (float) $mappedPrice;
        }

        return [
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'capacity' => $capacity,
            'price' => $price,
        ];
    }

    /**
     * @param array<int,mixed> $services
     * @return array<int,array<string,mixed>>
     */
    private function normalizeDiscoveredServices(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $id = $this->firstString($service, ['id', 'service_id', 'code', 'key', 'reference']);
            $name = $this->firstString($service, ['name', 'title', 'label', 'service_name']);
            $network = $this->normalizeNetwork($this->firstString($service, ['network', 'carrier', 'provider']) ?? '');
            $capacity = $this->firstScalar($service, ['capacity', 'size', 'volume']);
            $price = $this->firstNumeric($service, ['price', 'amount', 'cost', 'rate']);

            if (($id ?? '') === '' && ($name ?? '') === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id ?? $name,
                'name' => $name ?? $id,
                'network' => $network,
                'capacity' => $capacity,
                'price' => $price,
                'raw' => $service,
            ];
        }

        return $normalized;
    }

    /** @param array<string,mixed> $data */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if (is_scalar($value)) {
                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private function firstScalar(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if (is_scalar($value) && (string) $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private function firstNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
