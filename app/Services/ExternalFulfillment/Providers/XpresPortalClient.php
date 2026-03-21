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
        $baseUrl = rtrim((string) ($providerConfig['base_url'] ?? ''), '/');
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $apiSecret = (string) ($providerConfig['api_secret'] ?? '');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);
        $environment = (string) ($providerConfig['environment'] ?? 'sandbox');
        $allowedNetworks = (array) config('external_fulfillment.providers.xpresportal.networks', []);

        if ($baseUrl === '' || $apiKey === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'XpresPortal credentials are incomplete.',
            ];
        }

        $recipient = $this->normalizeMsisdn((string) $order->recipient_phone_number);
        if ($recipient === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Invalid recipient phone number for XpresPortal fulfillment.',
            ];
        }

        $serviceMapping = $this->resolveServiceMapping($order);
        $network = $this->resolveNetwork($order, $allowedNetworks);

        $metadata = array_filter([
            'order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'owner_vendor_id' => $order->owner_vendor_id,
            'reseller_vendor_id' => $order->reseller_vendor_id,
            'payment_gateway' => $order->payment_gateway,
            'mapped_service_id' => $serviceMapping['service_id'] ?? null,
            'mapped_service_name' => $serviceMapping['service_name'] ?? null,
            'mapped_offer_slug' => $serviceMapping['offer_slug'] ?? null,
        ], static fn ($value) => ! is_null($value) && $value !== '');

        $basePayload = [
            'reference' => (string) ($order->payment_reference ?? $idempotencyKey),
            'idempotency_key' => $idempotencyKey,
            'network' => $network,
            'amount' => (float) ($order->amount_paid ?? 0),
            'environment' => $environment,
            'metadata' => $metadata,
            'email' => (string) $order->customer_email,
        ];

        $payloadVariants = $this->buildOrderPayloadVariants(
            $basePayload,
            $serviceMapping,
            $recipient,
            (string) $order->display_service_name,
            (string) $order->service_purchased,
        );

        $headers = $this->headers($apiKey, $apiSecret, $environment, $idempotencyKey);
        $endpoints = $this->resolveOrderEndpoints($providerConfig, $baseUrl);

        $lastErrorMessage = 'Unable to submit order to XpresPortal.';
        $lastRaw = null;
        $lastReference = null;

        foreach ($endpoints as $url) {
            foreach ($payloadVariants as $payload) {
                try {
                    $response = Http::timeout($timeout)
                        ->acceptJson()
                        ->asJson()
                        ->withHeaders($headers)
                        ->post($url, $payload);

                    $json = $response->json();
                    $status = $this->normalizeStatus(data_get($json, 'status') ?? data_get($json, 'data.status') ?? null);
                    $remoteReference = $this->extractReference($json);

                    if ($response->successful()) {
                        if (in_array($status, ['failed', 'error', 'cancelled', 'rejected'], true)) {
                            $errorMessage = $this->buildErrorMessage($response->status(), $json ?? $response->body());

                            return [
                                'success' => false,
                                'status' => 'failed',
                                'external_reference' => $remoteReference,
                                'message' => $errorMessage,
                                'raw' => $json,
                            ];
                        }

                        if (in_array($status, ['pending', 'processing', 'queued', 'in_progress'], true)) {
                            return [
                                'success' => false,
                                'status' => 'pending',
                                'external_reference' => $remoteReference,
                                'message' => 'pending',
                                'raw' => $json,
                            ];
                        }

                        return [
                            'success' => true,
                            'status' => 'success',
                            'external_reference' => $remoteReference,
                            'message' => 'success',
                            'raw' => $json,
                        ];
                    }

                    $lastErrorMessage = $this->buildErrorMessage($response->status(), $json ?? $response->body());
                    $lastRaw = $json;
                    $lastReference = $remoteReference;

                    Log::warning('XpresPortal fulfillment attempt failed', [
                        'order_id' => $order->id,
                        'status' => $response->status(),
                        'endpoint' => $url,
                    ]);
                } catch (RequestException|ConnectionException $e) {
                    $lastErrorMessage = $this->limitMessage($e->getMessage());

                    Log::warning('XpresPortal fulfillment attempt exception', [
                        'order_id' => $order->id,
                        'endpoint' => $url,
                        'error' => $lastErrorMessage,
                    ]);
                }
            }
        }

        return [
            'success' => false,
            'status' => 'failed',
            'external_reference' => $lastReference,
            'message' => $lastErrorMessage,
            'raw' => $lastRaw,
        ];
    }

    public function getServices(): array
    {
        $providerConfig = $this->config->providerConfig('xpresportal');
        $baseUrl = rtrim(trim((string) ($providerConfig['base_url'] ?? '')), '/');
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $apiSecret = (string) ($providerConfig['api_secret'] ?? '');
        $environment = (string) ($providerConfig['environment'] ?? 'sandbox');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 30);
        $servicesEndpoint = (string) ($providerConfig['services_endpoint'] ?? '/api/v1/services');

        if ($baseUrl === '' || $apiKey === '') {
            return [];
        }

        try {
            $services = [];
            $dedupe = [];
            $headers = $this->headers($apiKey, $apiSecret, $environment);

            $directServices = $this->fetchDirectServices($baseUrl, $servicesEndpoint, $headers, $timeout);
            foreach ($directServices as $service) {
                $key = implode('|', [
                    (string) ($service['id'] ?? ''),
                    (string) ($service['network'] ?? ''),
                    (string) ($service['capacity'] ?? ''),
                ]);

                if (isset($dedupe[$key])) {
                    continue;
                }

                $dedupe[$key] = true;
                $services[] = $service;
            }

            // Only try per-network offers if direct services failed
            if (empty($services)) {
                $networks = $this->resolveDiscoveryNetworks((array) config('external_fulfillment.providers.xpresportal.networks', []));

                foreach ($networks as $network) {
                    $offers = $this->fetchOffersForNetwork($baseUrl, $network, $headers, $timeout);

                    foreach ($offers as $offer) {
                        $packages = $this->fetchPackagesForOffer($baseUrl, $network, $offer, $headers, $timeout);

                        // Safe fallback: if package endpoints are unavailable, derive packages from documented offer volumes.
                        if (empty($packages)) {
                            $packages = $this->expandOfferVolumesToPackages($offer);
                        }

                        foreach ($packages as $package) {
                            $service = $this->normalizeDiscoveredService($network, $offer, $package);
                            if (! is_array($service)) {
                                continue;
                            }

                            $key = implode('|', [
                                (string) ($service['id'] ?? ''),
                                (string) ($service['network'] ?? ''),
                                (string) ($service['capacity'] ?? ''),
                            ]);

                            if (isset($dedupe[$key])) {
                                continue;
                            }

                            $dedupe[$key] = true;
                            $services[] = $service;
                        }
                    }
                }
            }

            // Provider-safe fallback: if all other methods fail, use global offers endpoint.
            if (empty($services)) {
                $offers = $this->fetchGlobalOffers($baseUrl, $headers, $timeout);

                foreach ($offers as $offer) {
                    $network = $this->normalizeNetwork($this->firstString($offer, ['isp', 'network', 'carrier']) ?? '');
                    if ($network === '') {
                        continue;
                    }

                    $packages = $this->fetchPackagesForOffer($baseUrl, $network, $offer, $headers, $timeout);
                    if (empty($packages)) {
                        $packages = $this->expandOfferVolumesToPackages($offer);
                    }

                    foreach ($packages as $package) {
                        $service = $this->normalizeDiscoveredService($network, $offer, $package);
                        if (! is_array($service)) {
                            continue;
                        }

                        $key = implode('|', [
                            (string) ($service['id'] ?? ''),
                            (string) ($service['network'] ?? ''),
                            (string) ($service['capacity'] ?? ''),
                        ]);

                        if (isset($dedupe[$key])) {
                            continue;
                        }

                        $dedupe[$key] = true;
                        $services[] = $service;
                    }
                }
            }

            Log::info('XPRES SERVICES COUNT', [
                'count' => count($services),
                'provider' => 'xpresportal',
            ]);

            return $services;
        } catch (\Throwable $e) {
            Log::error('XPRES getServices exception', [
                'provider' => 'xpresportal',
                'error' => $this->limitMessage($e->getMessage()),
            ]);

            return [];
        }
    }

    private function headers(string $apiKey, ?string $apiSecret, string $environment, ?string $idempotencyKey = null): array
    {
        $headers = [
            'x-api-key' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (is_string($apiSecret) && trim($apiSecret) !== '') {
            $headers['x-api-secret'] = $apiSecret;
        }

        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $headers['idempotency-key'] = $idempotencyKey;
        }

        return $headers;
    }

    /** @return array<int,string> */
    private function resolveOrderEndpoints(array $providerConfig, string $baseUrl): array
    {
        $configuredEndpoint = trim((string) ($providerConfig['endpoint'] ?? '/api/v1/orders'));

        $candidates = [
            $configuredEndpoint,
            '/api/v1/orders',
            '/api/v1/order/mtn',
            '/api/v1/order/telecel',
            '/api/v1/order/airteltigo',
            '/api/v1/order/at',
            '/api/v1/order',
            '/api/orders',
            '/api/v1/purchase',
            '/api/v1/transactions',
        ];

        $urls = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $url = str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')
                ? $candidate
                : $baseUrl . '/' . ltrim($candidate, '/');

            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param array<string,mixed> $basePayload
     * @param array{service_id:string,service_name:string,capacity:mixed,price:?float,offer_slug:string} $serviceMapping
     * @return array<int,array<string,mixed>>
     */
    private function buildOrderPayloadVariants(
        array $basePayload,
        array $serviceMapping,
        string $recipient,
        string $displayServiceName,
        string $serviceDescription,
    ): array {
        // Primary payload - XpresPortal API specification
        $primaryPayload = [
            'type' => 'single',
            'phone' => $recipient,
            'volume' => $serviceMapping['capacity'],
            'offerSlug' => $serviceMapping['offer_slug'] !== '' ? $serviceMapping['offer_slug'] : null,
        ];

        // Fallback payload with alternative field names
        $fallbackPayload = [
            'type' => 'single',
            'phone' => $recipient,
            'msisdn' => $recipient,
            'volume' => $serviceMapping['capacity'],
            'offerSlug' => $serviceMapping['offer_slug'] !== '' ? $serviceMapping['offer_slug'] : null,
            'offer_slug' => $serviceMapping['offer_slug'] !== '' ? $serviceMapping['offer_slug'] : null,
            'service_id' => $serviceMapping['service_id'] !== '' ? $serviceMapping['service_id'] : null,
            'amount' => $basePayload['amount'] ?? null,
            'reference' => $basePayload['reference'] ?? null,
            'email' => $basePayload['email'] ?? null,
        ];

        return [
            $this->filterPayload($primaryPayload),
            $this->filterPayload($fallbackPayload),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function filterPayload(array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $nested = $this->filterPayload($value);
                if ($nested !== []) {
                    $filtered[$key] = $nested;
                }

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchDirectServices(string $baseUrl, string $servicesEndpoint, array $headers, int $timeout): array
    {
        $candidates = [
            '/api/v1/offers',
            '/api/offers',
        ];

        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $url = str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')
                ? $candidate
                : $baseUrl . '/' . ltrim($candidate, '/');

            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders($headers)
                    ->get($url);

                if (! $response->successful()) {
                    Log::warning('XPRES offers fetch failed', [
                        'status' => $response->status(),
                        'path' => parse_url($url, PHP_URL_PATH),
                    ]);
                    continue;
                }

                $json = $response->json();
                $offers = $this->extractList($json, ['offers', 'data.offers', 'data']);
                if (empty($offers) && is_array($json)) {
                    $offers = array_values(array_filter($json, static fn ($item) => is_array($item)));
                }

                if (! empty($offers)) {
                    $services = [];
                    foreach ($offers as $offer) {
                        $service = $this->normalizeOfferToService($offer);
                        if (is_array($service)) {
                            $services[] = $service;
                        }
                    }
                    return $services;
                }
            } catch (RequestException|ConnectionException $e) {
                Log::warning('XPRES offers fetch exception', [
                    'path' => parse_url($url, PHP_URL_PATH),
                    'error' => $this->limitMessage($e->getMessage()),
                ]);
            }
        }

        return [];
    }

    /** @return array<int,string> */
    private function resolveDiscoveryNetworks(array $configuredNetworks): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($network) => $this->normalizeNetwork($network),
            $configuredNetworks
        ))));

        if (! empty($normalized)) {
            return $normalized;
        }

        return ['MTN', 'TELECEL', 'AIRTELTIGO'];
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchOffersForNetwork(string $baseUrl, string $network, array $headers, int $timeout): array
    {
        $networkPath = strtolower($network);
        $candidates = [
            ['url' => $baseUrl . "/api/v1/{$networkPath}/offers", 'query' => []],
            ['url' => $baseUrl . "/api/{$networkPath}/offers", 'query' => []],
            ['url' => $baseUrl . '/api/v1/offers', 'query' => ['network' => $networkPath]],
            ['url' => $baseUrl . '/api/offers', 'query' => ['network' => $networkPath]],
        ];

        foreach ($candidates as $candidate) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->get($candidate['url'], $candidate['query']);

                if (! $response->successful()) {
                    Log::warning('XPRES offers fetch failed', [
                        'network' => $network,
                        'status' => $response->status(),
                        'path' => parse_url((string) $candidate['url'], PHP_URL_PATH),
                    ]);
                    continue;
                }

                $offers = $this->extractList($response->json(), ['offers', 'data.offers', 'data']);
                if (empty($offers)) {
                    continue;
                }

                $filtered = [];
                foreach ($offers as $offer) {
                    if (! is_array($offer)) {
                        continue;
                    }

                    $offerNetwork = $this->normalizeNetwork($this->firstString($offer, ['isp', 'network', 'carrier']) ?? '');
                    if ($offerNetwork === '' || $offerNetwork === $network) {
                        $filtered[] = $offer;
                    }
                }

                if (! empty($filtered)) {
                    return $filtered;
                }
            } catch (RequestException|ConnectionException $e) {
                Log::warning('XPRES offers fetch exception', [
                    'network' => $network,
                    'path' => parse_url((string) $candidate['url'], PHP_URL_PATH),
                    'error' => $this->limitMessage($e->getMessage()),
                ]);
            }
        }

        return [];
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchPackagesForOffer(string $baseUrl, string $network, array $offer, array $headers, int $timeout): array
    {
        $offerId = $this->firstString($offer, ['id', 'offer_id']);
        $offerSlug = $this->firstString($offer, ['offerSlug', 'offer_slug', 'slug']);
        $networkPath = strtolower($network);

        $candidates = [];

        if ($offerId) {
            $encodedId = rawurlencode($offerId);
            $candidates[] = ['url' => $baseUrl . "/api/v1/offers/{$encodedId}/packages", 'query' => []];
            $candidates[] = ['url' => $baseUrl . "/api/offers/{$encodedId}/packages", 'query' => []];
            $candidates[] = ['url' => $baseUrl . "/api/v1/{$networkPath}/offers/{$encodedId}/packages", 'query' => []];
            $candidates[] = ['url' => $baseUrl . '/api/v1/packages', 'query' => ['offer_id' => $offerId, 'network' => $networkPath]];
        }

        if ($offerSlug) {
            $encodedSlug = rawurlencode($offerSlug);
            $candidates[] = ['url' => $baseUrl . "/api/v1/offers/{$encodedSlug}/packages", 'query' => []];
            $candidates[] = ['url' => $baseUrl . "/api/offers/{$encodedSlug}/packages", 'query' => []];
            $candidates[] = ['url' => $baseUrl . '/api/v1/packages', 'query' => ['offerSlug' => $offerSlug, 'network' => $networkPath]];
        }

        foreach ($candidates as $candidate) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->get($candidate['url'], $candidate['query']);

                if (! $response->successful()) {
                    Log::warning('XPRES packages fetch failed', [
                        'network' => $network,
                        'offer' => $offerSlug ?? $offerId,
                        'status' => $response->status(),
                        'path' => parse_url((string) $candidate['url'], PHP_URL_PATH),
                    ]);
                    continue;
                }

                $packages = $this->extractList($response->json(), ['packages', 'data.packages', 'data']);
                if (! empty($packages)) {
                    return $packages;
                }
            } catch (RequestException|ConnectionException $e) {
                Log::warning('XPRES packages fetch exception', [
                    'network' => $network,
                    'offer' => $offerSlug ?? $offerId,
                    'path' => parse_url((string) $candidate['url'], PHP_URL_PATH),
                    'error' => $this->limitMessage($e->getMessage()),
                ]);
            }
        }

        return [];
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchGlobalOffers(string $baseUrl, array $headers, int $timeout): array
    {
        $candidates = [
            ['url' => $baseUrl . '/api/v1/offers', 'query' => []],
            ['url' => $baseUrl . '/api/offers', 'query' => []],
        ];

        foreach ($candidates as $candidate) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->get($candidate['url'], $candidate['query']);

                if (! $response->successful()) {
                    Log::warning('XPRES global offers fetch failed', [
                        'status' => $response->status(),
                        'path' => parse_url((string) $candidate['url'], PHP_URL_PATH),
                    ]);
                    continue;
                }

                $offers = $this->extractList($response->json(), ['offers', 'data.offers', 'data']);
                if (! empty($offers)) {
                    return $offers;
                }
            } catch (RequestException|ConnectionException $e) {
                Log::warning('XPRES global offers fetch exception', [
                    'path' => parse_url((string) $candidate['url'], PHP_URL_PATH),
                    'error' => $this->limitMessage($e->getMessage()),
                ]);
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $offer
     * @return array<int,array<string,mixed>>
     */
    private function expandOfferVolumesToPackages(array $offer): array
    {
        $volumes = data_get($offer, 'volumes');
        if (! is_array($volumes)) {
            return [];
        }

        $offerId = $this->firstString($offer, ['id', 'offer_id', 'offerSlug', 'offer_slug', 'slug', 'name']) ?? 'offer';
        $offerName = $this->firstString($offer, ['name', 'title', 'label']) ?? 'Package';
        $offerPrice = $this->firstNumeric($offer, ['price', 'amount', 'cost', 'rate']);

        $packages = [];
        foreach ($volumes as $volume) {
            if (! is_scalar($volume) || (string) $volume === '') {
                continue;
            }

            $volumeString = trim((string) $volume);
            $packages[] = [
                'id' => $offerId . '-' . $volumeString,
                'name' => trim($offerName . ' ' . $volumeString),
                'size' => $volume,
                'volume' => $volume,
                'price' => $offerPrice,
            ];
        }

        return $packages;
    }

    /**
     * @param array<string,mixed> $offer
     * @return array<string,mixed>|null
     */
    private function normalizeOfferToService(array $offer): ?array
    {
        $offerSlug = $this->firstString($offer, ['offerSlug', 'offer_slug', 'slug']);
        $network = $this->normalizeNetwork($this->firstString($offer, ['network', 'carrier', 'isp']) ?? '');
        $name = $this->firstString($offer, ['name', 'title', 'label']);
        $price = $this->firstNumeric($offer, ['price', 'amount', 'cost', 'rate']);

        if (! $offerSlug || ! $name) {
            return null;
        }

        return [
            'id' => $offerSlug,
            'name' => $name,
            'network' => $network,
            'capacity' => $offerSlug,
            'price' => $price,
            'provider' => 'xpresportal',
            'offer_slug' => $offerSlug,
        ];
    }

    /**
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $package
     * @return array<string,mixed>|null
     */
    private function normalizeDiscoveredService(string $network, array $offer, array $package): ?array
    {
        $serviceId = $this->firstString($package, ['id', 'service_id', 'code', 'key', 'reference']);
        $offerSlug = $this->firstString($offer, ['offerSlug', 'offer_slug', 'slug']);
        $capacity = $this->firstScalar($package, ['size', 'volume', 'capacity']);

        if ($serviceId === null) {
            $serviceId = $this->firstString($offer, ['id', 'offer_id']);
            if ($serviceId !== null && $capacity !== null) {
                $serviceId .= '-' . (string) $capacity;
            }
        }

        if ($serviceId === null && $offerSlug !== null && $capacity !== null) {
            $serviceId = $offerSlug . '-' . (string) $capacity;
        }

        $serviceName = $this->firstString($package, ['name', 'title', 'label', 'service_name']);
        if ($serviceName === null) {
            $offerName = $this->firstString($offer, ['name', 'title', 'label']) ?? 'Package';
            $serviceName = $capacity !== null ? trim($offerName . ' ' . (string) $capacity) : $offerName;
        }

        if ($serviceId === null || $serviceName === null) {
            return null;
        }

        $price = $this->firstNumeric($package, ['price', 'amount', 'cost', 'rate']);

        return [
            'id' => $serviceId,
            'name' => $serviceName,
            'network' => $this->normalizeNetwork($network),
            'capacity' => $capacity,
            'price' => $price,
            'provider' => 'xpresportal',
            'offer_name' => $this->firstString($offer, ['name', 'title', 'label']),
            'offer_slug' => $offerSlug,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function extractList(mixed $json, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($json, $path);
            if (is_array($value)) {
                $list = array_values(array_filter($value, static fn ($item) => is_array($item)));
                if (! empty($list)) {
                    return $list;
                }
            }
        }

        return [];
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

        return $statusString === '' ? 'unknown' : $statusString;
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

    /** @return array{service_id:string,service_name:string,capacity:mixed,price:?float,offer_slug:string} */
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

        $offerSlug = $this->firstString($metadata, [
            'external_mappings.xpresportal.offer_slug',
            'external_service_offer_slug',
        ]) ?? '';

        return [
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'capacity' => $capacity,
            'price' => $price,
            'offer_slug' => $offerSlug,
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
                'offer_slug' => $this->firstString($service, ['offer_slug', 'offerSlug', 'offer']),
                'provider' => 'xpresportal',
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
