<?php

namespace App\Services\ExternalFulfillment\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Services\ExternalFulfillment\Contracts\ExternalFulfillmentClient;
use App\Services\ExternalFulfillment\Contracts\SupportsStatusPolling;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GigshubClient implements ExternalFulfillmentClient, SupportsStatusPolling
{
    public function __construct(private ExternalFulfillmentConfig $config)
    {
    }

    public function sendOrder(Order $order, string $idempotencyKey): array
    {
        $providerConfig = $this->config->providerConfig('gigshub');
        $baseUrl = $this->normalizeBaseUrl((string) ($providerConfig['base_url'] ?? ''));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);

        if ($baseUrl === '' || $apiKey === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'GigsHub credentials are incomplete.',
            ];
        }

        $recipient = $this->toInternationalGhanaNumber((string) $order->recipient_phone_number);
        if ($recipient === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Invalid recipient phone number for GigsHub fulfillment.',
            ];
        }

        $network = $this->resolveGigshubNetwork($order);
        $volume = $this->resolveGigshubVolume($order);
        $offerSlug = $this->resolveGigshubOfferSlug($order);

        $allowedNetworks = array_map('strtolower', (array) config(
            'external_fulfillment.providers.gigshub.networks',
            ['mtn', 'telecel', 'airteltigo']
        ));

        $product = null;
        $metadata = [];
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $metadata = is_array($product?->decoded_description) ? $product->decoded_description : [];
        }

        $preflight = $this->preflightValidate($network, $volume, $offerSlug, $allowedNetworks, $metadata);
        if (! $preflight['ok']) {
            Log::warning('GigsHub fulfillment blocked by validation', [
                'order_id' => $order->id,
                'vendor_service_id' => $order->vendor_service_id,
                'network' => $network,
                'volume' => $volume,
                'offer_slug' => $offerSlug,
                'reason' => $preflight['reason'],
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'external_reference' => null,
                'message' => $preflight['reason'],
            ];
        }

        $webhookUrl = route('api.webhooks.gigshub', [], true);

        $payload = [
            'type' => 'single',
            'volume' => (int) $volume,
            'phone' => $recipient,
            'offerSlug' => (string) $offerSlug,
            'webhookUrl' => $webhookUrl,
        ];

        // GigsHub uses the network in the URL path: POST /api/v1/order/{network}
        $networkPath = strtolower($network);
        $url = $baseUrl . '/api/v1/order/' . $networkPath;

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
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

            Log::warning('GigsHub fulfillment failed', [
                'order_id' => $order->id,
                'status' => $status,
                'network' => $network,
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
        $providerConfig = $this->config->providerConfig('gigshub');
        $baseUrl = $this->normalizeBaseUrl((string) ($providerConfig['base_url'] ?? ''));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);

        if ($baseUrl === '' || $apiKey === '') {
            return [];
        }

        // GigsHub offers endpoint: GET /api/v1/offers
        $url = $baseUrl . '/api/v1/offers';

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('GigsHub services fetch failed', [
                    'status' => $response->status(),
                    'provider' => 'gigshub',
                ]);

                return [];
            }

            $json = $response->json();

            // GigsHub response: { "success": true, "offers": [ ... ] }
            $offers = data_get($json, 'offers');

            if (! is_array($offers)) {
                $offers = data_get($json, 'data.offers');
            }

            if (! is_array($offers)) {
                $offers = data_get($json, 'data');
            }

            if (! is_array($offers)) {
                return [];
            }

            return $this->normalizeDiscoveredServices($offers);
        } catch (RequestException|ConnectionException $e) {
            Log::warning('GigsHub services fetch failed', [
                'provider' => 'gigshub',
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [];
        }
    }

    public function getOrderStatus(string $identifier): array
    {
        $providerConfig = $this->config->providerConfig('gigshub');
        $baseUrl = $this->normalizeBaseUrl((string) ($providerConfig['base_url'] ?? ''));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);

        if ($baseUrl === '' || $apiKey === '' || $identifier === '') {
            return [
                'success' => false,
                'message' => 'Invalid configuration or identifier',
            ];
        }

        $url = $baseUrl . '/api/v1/order/status/' . urlencode($identifier);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                ])
                ->get($url)
                ->throw();

            $json = $response->json();

            return [
                'success' => true,
                'order' => data_get($json, 'order'),
                'raw' => $json,
            ];
        } catch (RequestException|ConnectionException $e) {
            $response = $e instanceof RequestException ? $e->response : null;
            $status = $response?->status();
            $body = $response?->json() ?? $response?->body();

            Log::warning('GigsHub order status check failed', [
                'identifier' => $identifier,
                'status' => $status,
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [
                'success' => false,
                'message' => $this->limitMessage($e->getMessage()),
                'raw' => $body,
            ];
        }
    }

    /**
     * Adapts the GigsHub status response to the polling contract.
     *
     * GigsHub nests the record under `order`; the status field has been seen as
     * both `status` and `orderStatus`, so both are accepted.
     */
    public function fetchRemoteStatus(string $remoteReference): array
    {
        $response = $this->getOrderStatus($remoteReference);

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'status' => null,
                'message' => $response['message'] ?? 'Status lookup failed.',
            ];
        }

        $status = data_get($response, 'order.status')
            ?? data_get($response, 'order.orderStatus')
            ?? data_get($response, 'raw.status');

        return [
            'success' => true,
            'status' => is_scalar($status) && trim((string) $status) !== '' ? (string) $status : null,
        ];
    }

    public function getBulkOrderStatus(array $identifiers): array
    {
        $providerConfig = $this->config->providerConfig('gigshub');
        $baseUrl = $this->normalizeBaseUrl((string) ($providerConfig['base_url'] ?? ''));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);

        if ($baseUrl === '' || $apiKey === '' || empty($identifiers)) {
            return [
                'success' => false,
                'message' => 'Invalid configuration or empty identifiers',
            ];
        }

        if (count($identifiers) > 100) {
            return [
                'success' => false,
                'message' => 'Maximum 100 identifiers allowed per request',
            ];
        }

        $url = $baseUrl . '/api/v1/order/status/bulk';

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                ])
                ->post($url, [
                    'identifiers' => $identifiers,
                ])
                ->throw();

            $json = $response->json();

            return [
                'success' => true,
                'total' => data_get($json, 'total'),
                'found' => data_get($json, 'found'),
                'notFound' => data_get($json, 'notFound'),
                'orders' => data_get($json, 'orders', []),
                'raw' => $json,
            ];
        } catch (RequestException|ConnectionException $e) {
            $response = $e instanceof RequestException ? $e->response : null;
            $status = $response?->status();
            $body = $response?->json() ?? $response?->body();

            Log::warning('GigsHub bulk order status check failed', [
                'count' => count($identifiers),
                'status' => $status,
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [
                'success' => false,
                'message' => $this->limitMessage($e->getMessage()),
                'raw' => $body,
            ];
        }
    }

    public function getBalance(): array
    {
        $providerConfig = $this->config->providerConfig('gigshub');
        $baseUrl = $this->normalizeBaseUrl((string) ($providerConfig['base_url'] ?? ''));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);

        if ($baseUrl === '' || $apiKey === '') {
            return [
                'success' => false,
                'message' => 'Invalid configuration',
            ];
        }

        $url = $baseUrl . '/api/v1/balance';

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                ])
                ->get($url)
                ->throw();

            $json = $response->json();

            return [
                'success' => true,
                'balance' => data_get($json, 'balance'),
                'currency' => data_get($json, 'currency'),
                'name' => data_get($json, 'name'),
                'timestamp' => data_get($json, 'timestamp'),
                'raw' => $json,
            ];
        } catch (RequestException|ConnectionException $e) {
            $response = $e instanceof RequestException ? $e->response : null;
            $status = $response?->status();
            $body = $response?->json() ?? $response?->body();

            Log::warning('GigsHub balance check failed', [
                'status' => $status,
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [
                'success' => false,
                'message' => $this->limitMessage($e->getMessage()),
                'raw' => $body,
            ];
        }
    }

    private function extractReference(mixed $json): ?string
    {
        // GigsHub returns: orderId, reference, id
        foreach (['orderId', 'reference', 'id', 'order_id', 'data.orderId', 'data.reference', 'data.id'] as $path) {
            $value = data_get($json, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        if ($trimmed === '') {
            return '';
        }

        $trimmed = preg_replace('#/api/v1$#i', '', $trimmed) ?? $trimmed;

        return rtrim($trimmed, '/');
    }

    private function limitMessage(string $message): string
    {
        return mb_substr($message, 0, 1000);
    }

    /**
     * Normalize a phone string into international Ghana format expected by GigsHub.
     * GigsHub expects 233XXXXXXXXX (international format without +).
     * Returns empty string when unable to produce a valid number.
     */
    private function toInternationalGhanaNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') return '';

        // Already in international format (233XXXXXXXXX)
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return $digits;
        }

        // Local format 0XXXXXXXXX → 233XXXXXXXXX
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '233' . substr($digits, 1);
        }

        // 9-digit number without prefix → 233XXXXXXXXX
        if (strlen($digits) === 9) {
            return '233' . $digits;
        }

        return '';
    }

    private function resolveGigshubNetwork(Order $order): string
    {
        $allowedNetworks = array_map('strtolower', (array) config(
            'external_fulfillment.providers.gigshub.networks',
            ['mtn', 'telecel', 'airteltigo']
        ));
        $network = null;
        $product = null;
        $explicitGigshubNetwork = null;

        // Check for explicit override on the order
        $overrideNetwork = $order->getAttribute('external_provider_network');
        if (is_string($overrideNetwork) && $overrideNetwork !== '') {
            $normalizedOverride = $this->normalizeNetwork($overrideNetwork);
            if (in_array($normalizedOverride, $allowedNetworks, true)) {
                return $normalizedOverride;
            }
        }

        // Prefer structured metadata from the product description
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $network = is_string(data_get($product, 'decoded_description.network'))
                ? (string) data_get($product, 'decoded_description.network')
                : null;

            // Check for explicit GigsHub mapping
            $mappedNetwork = data_get($product, 'decoded_description.external_mappings.gigshub.network');
            if (is_string($mappedNetwork) && $mappedNetwork !== '') {
                $explicitGigshubNetwork = $mappedNetwork;
            }

            if ($explicitGigshubNetwork === null) {
                $genericExternal = data_get($product, 'decoded_description.external_network');
                if (is_string($genericExternal) && $genericExternal !== '') {
                    $explicitGigshubNetwork = $genericExternal;
                }
            }

            $explicitGigshubNetwork = is_string(data_get($product, 'decoded_description.gigshub_network'))
                ? trim((string) data_get($product, 'decoded_description.gigshub_network'))
                : $explicitGigshubNetwork;
        }

        if (is_string($explicitGigshubNetwork) && $explicitGigshubNetwork !== '') {
            $explicitNormalized = $this->normalizeNetwork($explicitGigshubNetwork);
            if (in_array($explicitNormalized, $allowedNetworks, true)) {
                return $explicitNormalized;
            }
        }

        // Fallback to any captured order network
        if (! $network) {
            $network = is_string($order->mobile_money_network) ? $order->mobile_money_network : null;
        }

        $network = trim((string) ($network ?? ''));

        if ($network === '') {
            return 'mtn';
        }

        $normalized = $this->normalizeNetwork($network);

        // GigsHub-supported networks: mtn, telecel, airteltigo
        if (in_array($normalized, $allowedNetworks, true)) {
            return $normalized;
        }

        // Map common aliases
        if (in_array($normalized, ['vodafone'], true)) {
            return 'telecel';
        }

        if (in_array($normalized, ['airtel-tigo', 'tigo', 'airtel', 'airteltigo'], true)) {
            return 'at';
        }

        // Unknown network: send the normalized value (API may reject it)
        return $normalized;
    }

    private function resolveGigshubVolume(Order $order): string
    {
        $product = null;

        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);

            $mapped = data_get($product, 'decoded_description.external_mappings.gigshub.volume');
            if ($mapped === null || $mapped === '') {
                $mapped = data_get($product, 'decoded_description.external_mappings.gigshub.capacity');
            }

            $volume = $this->coerceWholeGbVolume($mapped);
            if ($volume !== null) {
                return (string) $volume;
            }

            $rawSize = data_get($product, 'decoded_description.size');
            $sizeVolume = $this->coerceWholeGbVolume($rawSize);
            if ($sizeVolume !== null) {
                return (string) $sizeVolume;
            }
        }

        $hints = [];
        $hints[] = (string) ($product?->name ?? '');
        $hints[] = (string) ($order->service_purchased ?? '');
        $hint = implode(' ', array_filter($hints));

        if ($hint !== '' && preg_match('/(\d+)\s*(gb|g)?/i', $hint, $m)) {
            return (string) max(1, (int) $m[1]);
        }

        return '1';
    }

    private function resolveGigshubOfferSlug(Order $order): ?string
    {
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);

            // Check for explicit GigsHub offerSlug mapping
            $mappedSlug = data_get($product, 'decoded_description.external_mappings.gigshub.offer_slug');
            if (is_string($mappedSlug) && $mappedSlug !== '') {
                return $mappedSlug;
            }

            // Check generic offer_slug
            $genericSlug = data_get($product, 'decoded_description.offer_slug');
            if (is_string($genericSlug) && $genericSlug !== '') {
                return $genericSlug;
            }

            // Check offerSlug (camelCase variant)
            $camelSlug = data_get($product, 'decoded_description.offerSlug');
            if (is_string($camelSlug) && $camelSlug !== '') {
                return $camelSlug;
            }
        }

        return null;
    }

    private function normalizeNetwork(string $network): string
    {
        return strtolower(preg_replace('/\s+/', '', $network));
    }

    /**
     * @return array{ok:bool,reason:string}
     */
    private function preflightValidate(
        string $network,
        string $volume,
        ?string $offerSlug,
        array $allowedNetworks,
        array $metadata,
    ): array {
        if ($network === '') {
            return ['ok' => false, 'reason' => 'Unable to determine GigsHub network for this order.'];
        }

        $networkNormalized = $this->normalizeNetwork($network);
        if (! empty($allowedNetworks) && ! in_array($networkNormalized, $allowedNetworks, true)) {
            return ['ok' => false, 'reason' => 'Invalid GigsHub network mapping [' . $network . '].'];
        }

        if (! is_string($offerSlug) || trim($offerSlug) === '') {
            return ['ok' => false, 'reason' => 'Missing GigsHub offerSlug mapping for this product.'];
        }

        if ($volume === '' || ! ctype_digit($volume) || (int) $volume <= 0) {
            return ['ok' => false, 'reason' => 'Invalid GigsHub volume for this product.'];
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    private function coerceWholeGbVolume(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (preg_match('/^\s*(\d+)\s*(gb)?\s*$/i', $trimmed, $m)) {
                $int = (int) $m[1];
                return $int > 0 ? $int : null;
            }
        }

        return null;
    }

    /**
     * Normalize GigsHub offers into the standard service format.
     *
     * GigsHub offers look like:
     * {
     *   "name": "MTN Data Bundle",
     *   "isp": "MTN",
     *   "type": "Data",
     *   "description": "...",
     *   "offerSlug": "mtn_data_bundle",
     *   "volumes": [1, 2, 3, 5, 10, ...]
     * }
     *
     * Each offer with volumes is expanded into individual services.
     *
     * @param array<int,mixed> $offers
     * @return array<int,array<string,mixed>>
     */
    private function normalizeDiscoveredServices(array $offers): array
    {
        $normalized = [];

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $offerSlug = $this->firstString($offer, ['offerSlug', 'offer_slug', 'slug']);
            $offerName = $this->firstString($offer, ['name', 'title', 'label']);
            $network = $this->normalizeNetwork($this->firstString($offer, ['isp', 'network', 'carrier']) ?? '');
            $type = $this->firstString($offer, ['type']);
            $price = $this->firstNumeric($offer, ['price', 'amount', 'cost', 'rate']);

            if (($offerSlug ?? '') === '' && ($offerName ?? '') === '') {
                continue;
            }

            // GigsHub offers include a "volumes" array — expand each volume into its own service entry
            $volumes = data_get($offer, 'volumes');
            if (is_array($volumes) && ! empty($volumes)) {
                foreach ($volumes as $volume) {
                    if (! is_scalar($volume) || (string) $volume === '') {
                        continue;
                    }

                    $volumeString = trim((string) $volume);
                    $serviceId = ($offerSlug ?? $offerName) . '-' . $volumeString;
                    $serviceName = trim(($offerName ?? $offerSlug) . ' ' . $volumeString . 'GB');

                    $normalized[] = [
                        'id' => $serviceId,
                        'name' => $serviceName,
                        'network' => $network,
                        'capacity' => $volumeString,
                        'price' => $price,
                        'provider' => 'gigshub',
                        'offer_slug' => $offerSlug,
                        'type' => $type,
                        'raw' => $offer,
                    ];
                }
            } else {
                // Offer without volumes — add as a single service entry
                $normalized[] = [
                    'id' => $offerSlug ?? $offerName,
                    'name' => $offerName ?? $offerSlug,
                    'network' => $network,
                    'capacity' => null,
                    'price' => $price,
                    'provider' => 'gigshub',
                    'offer_slug' => $offerSlug,
                    'type' => $type,
                    'raw' => $offer,
                ];
            }
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
