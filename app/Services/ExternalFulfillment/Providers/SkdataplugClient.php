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

/**
 * SKDataPlug external fulfillment provider.
 *
 * API base:  https://skdataplug.com/api/v1/
 * Auth:      Authorization: Bearer <token>
 *
 * Endpoints used:
 *   POST /api/v1/order/              — place a single order
 *   GET  /api/v1/bundles/            — list available bundles (getServices)
 *   GET  /api/v1/status/{order_id}/  — check order status
 *   POST /api/v1/callback/           — register webhook callback URL
 */
class SkdataplugClient implements ExternalFulfillmentClient, SupportsStatusPolling
{
    /** Base URL with trailing slash stripped. */
    private string $baseUrl;

    /** Bearer token. */
    private string $token;

    /** HTTP timeout in seconds. */
    private int $timeout;

    public function __construct(private ExternalFulfillmentConfig $config)
    {
        $providerConfig   = $this->config->providerConfig('skdataplug');
        $this->baseUrl    = rtrim(trim((string) ($providerConfig['base_url'] ?? '')), '/');
        $this->token      = (string) ($providerConfig['token'] ?? '');
        $this->timeout    = max(1, (int) ($providerConfig['timeout_seconds'] ?? 30));
    }

    // -------------------------------------------------------------------------
    // Contract: ExternalFulfillmentClient
    // -------------------------------------------------------------------------

    /**
     * Place a data-bundle order with SKDataPlug.
     *
     * Payload:
     *   { "recipient": "0XXXXXXXXX", "network": "MTN", "gb_size": "5" }
     *
     * @return array{success:bool,status?:string,external_reference?:string|null,message?:string|null,raw?:mixed}
     */
    public function sendOrder(Order $order, string $idempotencyKey): array
    {
        if (! $this->isCredentialsReady()) {
            return [
                'success' => false,
                'status'  => 'failed',
                'message' => 'SKDataPlug credentials are incomplete.',
            ];
        }

        $recipient = $this->toLocalGhanaNumber((string) $order->recipient_phone_number);
        if ($recipient === '') {
            return [
                'success' => false,
                'status'  => 'failed',
                'message' => 'Invalid recipient phone number for SKDataPlug fulfillment.',
            ];
        }

        $network = $this->resolveNetwork($order);
        $gbSize  = $this->resolveGbSize($order);

        $url     = $this->baseUrl . '/api/v1/order/';

        $payload = [
            'recipient' => $recipient,
            'network'   => $network,
            'gb_size'   => (string) $gbSize,
        ];

        Log::info('SKDataPlug: placing order', [
            'order_id'        => $order->id,
            'recipient'       => $recipient,
            'network'         => $network,
            'gb_size'         => $gbSize,
            'idempotency_key' => $idempotencyKey,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($this->token)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($url, $payload)
                ->throw();

            $json      = $response->json();
            $reference = $this->extractReference($json);

            Log::info('SKDataPlug: order placed successfully', [
                'order_id'  => $order->id,
                'reference' => $reference,
                'raw'       => $json,
            ]);

            return [
                'success'            => true,
                'status'             => 'success',
                'external_reference' => $reference,
                'message'            => 'success',
                'raw'                => $json,
            ];
        } catch (RequestException | ConnectionException $e) {
            $httpResponse = $e instanceof RequestException ? $e->response : null;
            $httpStatus   = $httpResponse?->status();
            $body         = $httpResponse?->json() ?? $httpResponse?->body();
            $message      = $this->limitMessage($e->getMessage());

            Log::warning('SKDataPlug: order placement failed', [
                'order_id'   => $order->id,
                'http_status' => $httpStatus,
                'error'      => $message,
                'body'       => $body,
            ]);

            return [
                'success'            => false,
                'status'             => 'failed',
                'external_reference' => null,
                'message'            => $message,
                'raw'                => $body,
            ];
        }
    }

    /**
     * Fetch available bundles from SKDataPlug.
     *
     * GET /api/v1/bundles/
     *
     * @return array<int, array<string, mixed>>
     */
    public function getServices(): array
    {
        if (! $this->isCredentialsReady()) {
            return [];
        }

        $url = $this->baseUrl . '/api/v1/bundles/';

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withToken($this->token)
                ->get($url);

            if (! $response->successful()) {
                Log::warning('SKDataPlug: bundles fetch failed', [
                    'status'   => $response->status(),
                    'provider' => 'skdataplug',
                ]);

                return [];
            }

            $json    = $response->json();
            $bundles = $this->extractList($json);

            if (empty($bundles)) {
                return [];
            }

            return $this->normalizeBundles($bundles);
        } catch (RequestException | ConnectionException $e) {
            Log::warning('SKDataPlug: bundles fetch exception', [
                'provider' => 'skdataplug',
                'error'    => $this->limitMessage($e->getMessage()),
            ]);

            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Extra (beyond contract): order status & webhook registration
    // -------------------------------------------------------------------------

    /**
     * Check the status of a previously placed order.
     *
     * GET /api/v1/status/{order_id}/
     *
     * @return array{success:bool,order_id?:string,status?:string,raw?:mixed,message?:string}
     */
    public function getOrderStatus(string $remoteOrderId): array
    {
        if (! $this->isCredentialsReady() || trim($remoteOrderId) === '') {
            return [
                'success' => false,
                'message' => 'Invalid configuration or order ID.',
            ];
        }

        $url = $this->baseUrl . '/api/v1/status/' . rawurlencode($remoteOrderId) . '/';

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withToken($this->token)
                ->get($url)
                ->throw();

            $json = $response->json();

            return [
                'success'  => true,
                'order_id' => data_get($json, 'order_id'),
                'status'   => data_get($json, 'status'),
                'raw'      => $json,
            ];
        } catch (RequestException | ConnectionException $e) {
            $httpResponse = $e instanceof RequestException ? $e->response : null;
            $body         = $httpResponse?->json() ?? $httpResponse?->body();

            Log::warning('SKDataPlug: order status check failed', [
                'remote_order_id' => $remoteOrderId,
                'error'           => $this->limitMessage($e->getMessage()),
            ]);

            return [
                'success' => false,
                'message' => $this->limitMessage($e->getMessage()),
                'raw'     => $body,
            ];
        }
    }

    /**
     * Adapts the SKDataPlug status response to the polling contract.
     */
    public function fetchRemoteStatus(string $remoteReference): array
    {
        $response = $this->getOrderStatus($remoteReference);

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'status'  => null,
                'message' => $response['message'] ?? 'Status lookup failed.',
            ];
        }

        $status = $response['status'] ?? null;

        return [
            'success' => true,
            'status'  => is_scalar($status) && trim((string) $status) !== '' ? (string) $status : null,
        ];
    }

    /**
     * Register (or update) the delivery webhook callback URL with SKDataPlug.
     *
     * POST /api/v1/callback/
     * Body: { "callback_url": "https://yourserver.com/webhooks/skdataplug" }
     *
     * Only needs to be called once (or when the URL changes).
     *
     * @return array{success:bool,message?:string,callback_url?:string,raw?:mixed}
     */
    public function registerCallback(string $callbackUrl): array
    {
        if (! $this->isCredentialsReady() || trim($callbackUrl) === '') {
            return [
                'success' => false,
                'message' => 'Invalid configuration or callback URL.',
            ];
        }

        $url = $this->baseUrl . '/api/v1/callback/';

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($this->token)
                ->post($url, ['callback_url' => $callbackUrl])
                ->throw();

            $json = $response->json();

            Log::info('SKDataPlug: callback URL registered', [
                'callback_url' => $callbackUrl,
            ]);

            return [
                'success'      => true,
                'message'      => data_get($json, 'message'),
                'callback_url' => data_get($json, 'callback_url'),
                'raw'          => $json,
            ];
        } catch (RequestException | ConnectionException $e) {
            $httpResponse = $e instanceof RequestException ? $e->response : null;
            $body         = $httpResponse?->json() ?? $httpResponse?->body();

            Log::warning('SKDataPlug: callback registration failed', [
                'callback_url' => $callbackUrl,
                'error'        => $this->limitMessage($e->getMessage()),
            ]);

            return [
                'success' => false,
                'message' => $this->limitMessage($e->getMessage()),
                'raw'     => $body,
            ];
        }
    }

    /**
     * Get the currently registered callback URL from SKDataPlug.
     *
     * GET /api/v1/callback/
     *
     * @return array{success:bool,callback_url?:string,raw?:mixed,message?:string}
     */
    public function getRegisteredCallback(): array
    {
        if (! $this->isCredentialsReady()) {
            return [
                'success' => false,
                'message' => 'SKDataPlug credentials are incomplete.',
            ];
        }

        $url = $this->baseUrl . '/api/v1/callback/';

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withToken($this->token)
                ->get($url)
                ->throw();

            $json = $response->json();

            return [
                'success'      => true,
                'callback_url' => data_get($json, 'callback_url'),
                'raw'          => $json,
            ];
        } catch (RequestException | ConnectionException $e) {
            return [
                'success' => false,
                'message' => $this->limitMessage($e->getMessage()),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function isCredentialsReady(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * Extract a remote reference ID from the API response.
     * SKDataPlug returns: order_id, id, reference (check common fields).
     */
    private function extractReference(mixed $json): ?string
    {
        foreach (['order_id', 'id', 'reference', 'data.order_id', 'data.id', 'data.reference'] as $path) {
            $value = data_get($json, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Resolve the network string to send to SKDataPlug.
     *
     * Priority:
     *   1. Explicit order override (`external_provider_network`)
     *   2. Product metadata → external_mappings.skdataplug.network
     *   3. Product metadata → external_network (generic)
     *   4. Product metadata → skdataplug_network
     *   5. Order mobile_money_network
     *   6. Default: 'MTN'
     */
    private function resolveNetwork(Order $order): string
    {
        $allowedNetworks = array_map(
            'strtoupper',
            (array) config('external_fulfillment.providers.skdataplug.networks', ['MTN', 'TELECEL', 'AIRTELTIGO'])
        );

        // 1. Explicit order override
        $override = $order->getAttribute('external_provider_network');
        if (is_string($override) && $override !== '') {
            $normalized = strtoupper(trim($override));
            if (in_array($normalized, $allowedNetworks, true)) {
                return $normalized;
            }
        }

        $product  = null;
        $metadata = [];
        if (! empty($order->vendor_service_id)) {
            $product  = Product::query()->find((int) $order->vendor_service_id);
            $metadata = is_array($product?->decoded_description) ? $product->decoded_description : [];
        }

        // 2. Provider-specific mapping
        $mapped = data_get($metadata, 'external_mappings.skdataplug.network');
        if (is_string($mapped) && $mapped !== '') {
            return strtoupper(trim($mapped));
        }

        // 3. Generic external network
        $generic = data_get($metadata, 'external_network');
        if (is_string($generic) && $generic !== '') {
            return strtoupper(trim($generic));
        }

        // 4. Product-level skdataplug_network field
        $explicit = data_get($metadata, 'skdataplug_network');
        if (is_string($explicit) && trim($explicit) !== '') {
            return strtoupper(trim($explicit));
        }

        // 5. Order MoMo network
        $momoNetwork = is_string($order->mobile_money_network)
            ? strtoupper(trim($order->mobile_money_network))
            : '';

        if ($momoNetwork !== '') {
            // Common alias mapping
            if (in_array($momoNetwork, ['VODAFONE'], true)) {
                return 'TELECEL';
            }
            if (in_array($momoNetwork, ['AIRTEL', 'TIGO', 'AIRTEL-TIGO', 'AIRTELTIGO'], true)) {
                return 'AIRTELTIGO';
            }

            if (in_array($momoNetwork, $allowedNetworks, true)) {
                return $momoNetwork;
            }
        }

        // 6. Safe default
        return 'MTN';
    }

    /**
     * Resolve the gb_size (integer GB) to send to SKDataPlug.
     *
     * Priority:
     *   1. Product metadata → external_mappings.skdataplug.gb_size
     *   2. Product metadata → size
     *   3. Parse digits from product name / order service_purchased
     *   4. Default: 1
     */
    private function resolveGbSize(Order $order): int
    {
        $product = null;

        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);

            // 1. Explicit provider mapping
            $mapped = data_get($product, 'decoded_description.external_mappings.skdataplug.gb_size');
            if ($mapped === null || $mapped === '') {
                $mapped = data_get($product, 'decoded_description.external_mappings.skdataplug.capacity');
            }
            if (is_numeric($mapped)) {
                return max(1, (int) $mapped);
            }
            if (is_string($mapped) && preg_match('/(\d+)/', $mapped, $m)) {
                return max(1, (int) $m[1]);
            }

            // 2. Generic size field
            $rawSize = data_get($product, 'decoded_description.size');
            if (is_numeric($rawSize)) {
                return max(1, (int) $rawSize);
            }
            if (is_string($rawSize) && preg_match('/(\d+)/', $rawSize, $m)) {
                return max(1, (int) $m[1]);
            }
        }

        // 3. Parse from display names
        $hints   = [];
        $hints[] = (string) ($product?->name ?? '');
        $hints[] = (string) ($order->service_purchased ?? '');
        $hint    = implode(' ', array_filter($hints));

        if ($hint !== '' && preg_match('/(\d+)\s*(gb|g)?/i', $hint, $m)) {
            return max(1, (int) $m[1]);
        }

        // 4. Safe default
        return 1;
    }

    /**
     * Convert any Ghana phone format to local 0XXXXXXXXX expected by SKDataPlug.
     */
    private function toLocalGhanaNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') {
            return '';
        }

        // International format: 233XXXXXXXXX → 0XXXXXXXXX
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return '0' . substr($digits, 3);
        }

        // Local format: already 0XXXXXXXXX
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return substr($digits, 0, 10);
        }

        // 9-digit (no prefix) → prefix with 0
        if (strlen($digits) === 9) {
            return '0' . $digits;
        }

        return '';
    }

    /**
     * Extract an array list from common response wrapper shapes.
     *
     * @return array<int, mixed>
     */
    private function extractList(mixed $json): array
    {
        foreach (['bundles', 'data.bundles', 'packages', 'data.packages', 'data', 'results'] as $path) {
            $value = data_get($json, $path);
            if (is_array($value) && ! empty($value)) {
                return array_values(array_filter($value, static fn ($item) => is_array($item)));
            }
        }

        // Top-level array
        if (is_array($json) && ! empty($json)) {
            $list = array_values(array_filter($json, static fn ($item) => is_array($item)));
            if (! empty($list)) {
                return $list;
            }
        }

        return [];
    }

    /**
     * Normalize SKDataPlug bundle entries into the standard internal service format.
     *
     * Expected bundle shape (illustrative, normalizer is defensive):
     *   { "id": "MTN-5GB", "network": "MTN", "gb_size": 5, "price": 20.00, "name": "MTN 5GB Bundle" }
     *
     * @param  array<int, mixed> $bundles
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBundles(array $bundles): array
    {
        $normalized = [];

        foreach ($bundles as $bundle) {
            if (! is_array($bundle)) {
                continue;
            }

            $id      = $this->firstString($bundle, ['id', 'bundle_id', 'code', 'key']);
            $name    = $this->firstString($bundle, ['name', 'title', 'label', 'description']);
            $network = strtoupper(trim($this->firstString($bundle, ['network', 'carrier', 'isp']) ?? ''));
            $gbSize  = $this->firstScalar($bundle, ['gb_size', 'size', 'volume', 'capacity']);
            $price   = $this->firstNumeric($bundle, ['price', 'amount', 'cost', 'rate']);

            if (($id ?? '') === '' && ($name ?? '') === '') {
                continue;
            }

            $normalized[] = [
                'id'       => $id ?? $name,
                'name'     => $name ?? $id,
                'network'  => $network,
                'capacity' => $gbSize,
                'price'    => $price,
                'provider' => 'skdataplug',
                'raw'      => $bundle,
            ];
        }

        return $normalized;
    }

    private function limitMessage(string $message): string
    {
        return mb_substr($message, 0, 1000);
    }

    /** @param array<string, mixed> $data */
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

    /** @param array<string, mixed> $data */
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

    /** @param array<string, mixed> $data */
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
