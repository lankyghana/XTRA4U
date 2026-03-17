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

class DatafyhubClient implements ExternalFulfillmentClient
{
    public function __construct(private ExternalFulfillmentConfig $config)
    {
    }

    public function sendOrder(Order $order, string $idempotencyKey): array
    {
        $recipient = $this->toLocalGhanaNumber((string) $order->recipient_phone_number);
        if ($recipient === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Invalid recipient phone number for external fulfillment.',
            ];
        }

        $payload = [
            'network' => $this->resolveDatafyhubNetwork($order),
            'reference' => $this->resolveDatafyhubReference($order, $idempotencyKey),
            'recipient' => $recipient,
            'capacity' => $this->resolveDatafyhubCapacity($order),
        ];

        $providerConfig = $this->config->providerConfig('datafyhub');
        $url = rtrim((string) ($providerConfig['base_url'] ?? ''), '/') . '/' . ltrim((string) ($providerConfig['endpoint'] ?? ''), '/');
        $token = (string) ($providerConfig['token'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 10);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeaders([
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->post($url, $payload)
                ->throw();

            $json = $response->json();
            $remoteReference = $this->extractReference($json);

            return [
                'success' => true,
                'status' => 'success',
                'external_reference' => $remoteReference,
                'message' => 'success',
                'raw' => $json,
            ];
        } catch (RequestException|ConnectionException $e) {
            $response = $e instanceof RequestException ? $e->response : null;
            $status = $response?->status();
            $body = $response?->json() ?? $response?->body();
            $message = $e->getMessage();

            Log::warning('Datafyhub fulfillment failed', [
                'order_id' => $order->id,
                'status' => $status,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'external_reference' => null,
                'message' => $this->limitMessage($message),
                'raw' => $body,
            ];
        }
    }

    public function getServices(): array
    {
        $providerConfig = $this->config->providerConfig('datafyhub');
        $baseUrl = trim((string) ($providerConfig['base_url'] ?? ''));
        $endpoint = trim((string) ($providerConfig['services_endpoint'] ?? '/services'));
        $token = (string) ($providerConfig['token'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 10);

        if ($baseUrl === '' || $endpoint === '' || $token === '') {
            return [];
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($token)
                ->get($url);

            if (! $response->successful()) {
                Log::warning('Datafyhub services fetch failed', [
                    'status' => $response->status(),
                    'provider' => 'datafyhub',
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
            Log::warning('Datafyhub services fetch failed', [
                'provider' => 'datafyhub',
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [];
        }
    }

    private function extractReference(mixed $json): ?string
    {
        foreach (['reference', 'id', 'transaction_id', 'data.reference', 'data.id'] as $path) {
            $value = data_get($json, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function limitMessage(string $message): string
    {
        return mb_substr($message, 0, 1000);
    }

    /**
     * Normalize a phone string into local Ghana format expected by Datafyhub.
     * Returns empty string when unable to produce a valid local number.
     */
    private function toLocalGhanaNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') return '';

        if (str_starts_with($digits, '233') && strlen($digits) > 3) {
            return '0' . substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return $digits;
        }

        // If 9 digits, prefix 0
        if (strlen($digits) === 9) {
            return '0' . $digits;
        }

        return '';
    }

    private function resolveDatafyhubReference(Order $order, string $idempotencyKey): string
    {
        $reference = (string) ($order->payment_reference ?? '');
        if ($reference !== '') {
            return $reference;
        }

        return $idempotencyKey;
    }

    private function resolveDatafyhubNetwork(Order $order): string
    {
        $allowedNetworks = array_map('strtolower', (array) config('external_fulfillment.providers.datafyhub.networks', ['mtn', 'telecel', 'ishare', 'bigtime', 'xpress']));
        $network = null;
        $product = null;
        $explicitDatafyhubNetwork = null;

        $overrideNetwork = $order->getAttribute('external_provider_network');
        if (is_string($overrideNetwork) && $overrideNetwork !== '') {
            $normalizedOverride = $this->normalizeNetwork($overrideNetwork);
            if (in_array($normalizedOverride, $allowedNetworks, true)) {
                return $normalizedOverride;
            }
        }

        // Prefer structured metadata from the product description.
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $network = is_string(data_get($product, 'decoded_description.network'))
                ? (string) data_get($product, 'decoded_description.network')
                : null;

            $mappedNetwork = data_get($product, 'decoded_description.external_mappings.datafyhub.network');
            if (is_string($mappedNetwork) && $mappedNetwork !== '') {
                $explicitDatafyhubNetwork = $mappedNetwork;
            }

            if ($explicitDatafyhubNetwork === null) {
                $genericExternal = data_get($product, 'decoded_description.external_network');
                if (is_string($genericExternal) && $genericExternal !== '') {
                    $explicitDatafyhubNetwork = $genericExternal;
                }
            }

            $explicitDatafyhubNetwork = is_string(data_get($product, 'decoded_description.datafyhub_network'))
                ? trim((string) data_get($product, 'decoded_description.datafyhub_network'))
                : $explicitDatafyhubNetwork;
        }

        if ($explicitDatafyhubNetwork !== '') {
            $explicitNormalized = $this->normalizeNetwork((string) $explicitDatafyhubNetwork);
            if (in_array($explicitNormalized, $allowedNetworks, true)) {
                return $explicitNormalized;
            }
        }

        // Fallback to any captured order network.
        if (! $network) {
            $network = is_string($order->mobile_money_network) ? $order->mobile_money_network : null;
        }

        $network = trim((string) ($network ?? ''));

        if ($network === '') {
            // Datafyhub requires a network key; default to mtn as a safe fallback.
            return 'mtn';
        }

        $normalized = $this->normalizeNetwork($network);

        // Datafyhub-supported networks (per docs): mtn, telecel, ishare, bigtime, xpress
        if (in_array($normalized, $allowedNetworks, true)) {
            return $normalized;
        }

        if (in_array($normalized, ['vodafone'], true)) {
            return 'telecel';
        }

        if (in_array($normalized, ['airteltigo', 'airtel-tigo', 'tigo', 'airtel'], true)) {
            $hints = [];
            $hints[] = (string) ($product?->name ?? '');
            $hints[] = (string) ($order->service_purchased ?? '');
            $hints[] = (string) data_get($product, 'decoded_description.tag', '');
            $hints[] = (string) data_get($product, 'decoded_description.notes', '');
            $hint = strtolower(implode(' ', array_filter($hints)));

            if (str_contains($hint, 'ishare') || str_contains($hint, 'i-share')) {
                return 'ishare';
            }

            if (str_contains($hint, 'bigtime') || str_contains($hint, 'big time')) {
                return 'bigtime';
            }

            if (str_contains($hint, 'xpress')) {
                return 'xpress';
            }

            // If the product/network metadata doesn't disambiguate AirtelTigo package type,
            // prefer failing fast over silently sending an invalid network.
            return 'invalid';
        }

        // Unknown network: send the normalized value (external API may reject it).
        return $normalized;
    }

    private function resolveDatafyhubCapacity(Order $order): int
    {
        // Try to parse the product "size" metadata (commonly something like "2" or "2GB").
        $raw = null;
        $product = null;

        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $mappedCapacity = data_get($product, 'decoded_description.external_mappings.datafyhub.capacity');
            if (is_numeric($mappedCapacity)) {
                return max(1, (int) $mappedCapacity);
            }
            if (is_string($mappedCapacity) && preg_match('/(\d+)/', $mappedCapacity, $mappedMatches)) {
                return max(1, (int) $mappedMatches[1]);
            }
            $raw = data_get($product, 'decoded_description.size');
        }

        if (is_numeric($raw)) {
            $capacity = (int) $raw;
            return max(1, $capacity);
        }

        if (is_string($raw)) {
            if (preg_match('/(\d+)/', $raw, $m)) {
                return max(1, (int) $m[1]);
            }
        }

        // Fallback: parse digits from product/order display names.
        $hints = [];
        $hints[] = (string) ($product?->name ?? '');
        $hints[] = (string) ($order->service_purchased ?? '');
        $hint = implode(' ', array_filter($hints));

        if ($hint !== '' && preg_match('/(\d+)\s*(gb|g)?/i', $hint, $m)) {
            return max(1, (int) $m[1]);
        }

        return 1;
    }

    private function normalizeNetwork(string $network): string
    {
        return strtolower(preg_replace('/\s+/', '', $network));
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
