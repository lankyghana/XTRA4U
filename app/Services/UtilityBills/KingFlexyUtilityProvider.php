<?php

namespace App\Services\UtilityBills;

use App\Services\UtilityBills\Data\Biller;
use App\Services\UtilityBills\Data\Catalog;
use App\Services\UtilityBills\Data\LookupResult;
use App\Services\UtilityBills\Data\PayResult;
use App\Services\UtilityBills\Data\StatusResult;
use App\Services\UtilityBills\Exceptions\NotConfigured;
use App\Services\UtilityBills\Exceptions\ProviderDuplicateWindow;
use App\Services\UtilityBills\Exceptions\ProviderInsufficientBalance;
use App\Services\UtilityBills\Exceptions\ProviderMalformedResponse;
use App\Services\UtilityBills\Exceptions\ProviderNotFound;
use App\Services\UtilityBills\Exceptions\ProviderRateLimited;
use App\Services\UtilityBills\Exceptions\ProviderRejected;
use App\Services\UtilityBills\Exceptions\ProviderServerError;
use App\Services\UtilityBills\Exceptions\ProviderServiceDisabled;
use App\Services\UtilityBills\Exceptions\ProviderUnauthorized;
use App\Services\UtilityBills\Exceptions\ProviderUnreachable;
use App\Services\UtilityBills\Exceptions\UtilityProviderException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * KiNG FLEXY GH — Utility Bills (Commission Services API, v2).
 *
 * Server-to-server only. The Commission Services key (`kf_cs_live_...`) is read
 * from config, sent only as the Authorization header, and never logged, thrown
 * or returned. Endpoints (verified against the provider docs):
 *
 *   GET  /utilities/billers              catalog + global min/max      (30/min)
 *   GET  /utilities/lookup               verify account / list meters  (10/min)
 *   POST /utilities/pay                  pay at face value; idempotent (6/min)
 *   GET  /utilities/orders/{reference}   status by the PROVIDER's ref  (30/min)
 *
 * No request is auto-retried here. A POST is only ever retried by the caller,
 * and only with the same persisted `reference` (the provider's idempotency key).
 */
class KingFlexyUtilityProvider
{
    private const CATALOG_KEY = 'utility_bills.catalog';

    private const CATALOG_STALE_KEY = 'utility_bills.catalog.stale';

    public function isConfigured(): bool
    {
        $key = UtilityBillCredentials::apiKey();

        // A normal data key (kf_live_...) is rejected by the provider on every
        // utility endpoint; refuse to use anything that is not a Commission key.
        return $key !== '' && str_starts_with($key, 'kf_cs_');
    }

    /**
     * Fresh-or-recently-fetched catalog. Throws when the provider cannot be
     * reached — callers deciding whether a SALE may proceed must not fall back
     * to an old copy (see catalogForDisplay()).
     */
    public function catalog(): Catalog
    {
        $cached = Cache::get(self::CATALOG_KEY);
        if (is_array($cached)) {
            return Catalog::fromArray($cached);
        }

        $response = $this->send('billers', 'get', '/utilities/billers');
        $catalog = $this->parseCatalog($response);

        $ttl = max(1, (int) config('utility_bills.catalog_ttl'));
        Cache::put(self::CATALOG_KEY, $catalog->toArray(), $ttl);
        Cache::put(self::CATALOG_STALE_KEY, $catalog->toArray(), max($ttl, (int) config('utility_bills.catalog_stale_ttl')));
        Cache::forever('utility_bills.catalog.last_success_at', time());

        return $catalog;
    }

    /**
     * Same as catalog(), but when the provider is down shows the last good copy
     * (up to catalog_stale_ttl old) so pages can still render. Never used to
     * authorise a sale.
     *
     * @return array{0: ?Catalog, 1: bool} [catalog, isStale]
     */
    public function catalogForDisplay(): array
    {
        try {
            return [$this->catalog(), false];
        } catch (UtilityProviderException) {
            $stale = Cache::get(self::CATALOG_STALE_KEY);

            return is_array($stale) ? [Catalog::fromArray($stale), true] : [null, false];
        }
    }

    public function forgetCatalog(): void
    {
        Cache::forget(self::CATALOG_KEY);
    }

    public function lookup(string $biller, string $account, ?string $phone = null): LookupResult
    {
        $query = ['biller' => $biller, 'account' => $account];
        if ($phone !== null && $phone !== '') {
            $query['phone'] = $phone;
        }

        $response = $this->send('lookup', 'get', '/utilities/lookup', ['query' => $query], ['biller' => $biller]);

        return $this->parseLookup($response);
    }

    /**
     * @param  string  $amount  decimal string, e.g. "65.00"
     * @param  string  $reference  OUR idempotency key; must already be persisted
     */
    public function pay(string $biller, string $account, string $amount, string $reference, ?string $phone = null): PayResult
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $reference)) {
            throw new InvalidArgumentException('Invalid provider request reference.');
        }

        $body = [
            'biller' => $biller,
            'account' => $account,
            'amount' => (float) $amount,
            'reference' => $reference,
        ];
        if ($phone !== null && $phone !== '') {
            $body['phone'] = $phone;
        }

        $response = $this->send('pay', 'post', '/utilities/pay', ['json' => $body], ['biller' => $biller], isPay: true);

        return $this->parsePay($response);
    }

    public function status(string $providerReference): StatusResult
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $providerReference)) {
            throw new InvalidArgumentException('Invalid provider order reference.');
        }

        $response = $this->send('status', 'get', '/utilities/orders/'.rawurlencode($providerReference));

        return $this->parseStatus($response);
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * @param  array{query?:array,json?:array}  $options
     */
    private function send(string $endpoint, string $method, string $path, array $options = [], array $logContext = [], bool $isPay = false): Response
    {
        if (! $this->isConfigured()) {
            throw new NotConfigured('Commission Services API key is not configured.');
        }

        $limitKey = 'utility-bills:provider:'.$endpoint;
        $perMinute = (int) config('utility_bills.rate.'.$endpoint.'_per_minute', 5);

        if (RateLimiter::tooManyAttempts($limitKey, $perMinute)) {
            Log::warning('utility_bills.provider.local_rate_limited', ['endpoint' => $endpoint] + $logContext);
            throw new ProviderRateLimited('Local provider rate budget exhausted.');
        }
        RateLimiter::hit($limitKey, 60);

        try {
            $http = $this->client();
            $response = $method === 'post'
                ? $http->post($path, $options['json'] ?? [])
                : $http->get($path, $options['query'] ?? []);
        } catch (ConnectionException $e) {
            Log::warning('utility_bills.provider.unreachable', ['endpoint' => $endpoint, 'error' => class_basename($e)] + $logContext);
            throw new ProviderUnreachable('Provider unreachable on '.$endpoint.'.');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->exceptionFor($response, $endpoint, $logContext);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.kingflexy_utilities.base_url'), '/'))
            ->withHeaders(['Authorization' => UtilityBillCredentials::apiKey()])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.kingflexy_utilities.connect_timeout', 5))
            ->timeout((int) config('services.kingflexy_utilities.timeout', 20));
    }

    private function exceptionFor(Response $response, string $endpoint, array $logContext): UtilityProviderException
    {
        $status = $response->status();
        $message = $this->providerMessage($response);

        Log::warning('utility_bills.provider.error', [
            'endpoint' => $endpoint,
            'http_status' => $status,
            // Provider-authored text, truncated; it never contains our key.
            'provider_message' => $message,
        ] + $logContext);

        return match (true) {
            in_array($status, [401, 403], true) => new ProviderUnauthorized('Provider rejected credentials.', $status),
            $status === 404 => new ProviderNotFound('Not found on '.$endpoint.'.', $status),
            $status === 409 => new ProviderDuplicateWindow('Provider duplicate-payment window on '.$endpoint.'.', $status),
            $status === 429 => new ProviderRateLimited('Provider rate limit hit on '.$endpoint.'.', $status),
            $status === 503 => new ProviderServiceDisabled('Provider reports the service/biller disabled.', $status),
            $status === 400 && $message !== null && preg_match('/insufficient|not enough|low balance|wallet/i', $message) === 1
                => new ProviderInsufficientBalance('Provider wallet cannot cover this bill.', $status),
            $status >= 500 => new ProviderServerError('Provider error '.$status.' on '.$endpoint.'.', $status),
            default => new ProviderRejected('Provider rejected the request on '.$endpoint.' ('.$status.').', $status),
        };
    }

    private function providerMessage(Response $response): ?string
    {
        $json = $response->json();
        $message = is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null;

        return is_string($message) ? self::clean($message, 200) : null;
    }

    // ------------------------------------------------------------------
    // Parsing / normalisation
    // ------------------------------------------------------------------

    private function parseCatalog(Response $response): Catalog
    {
        $data = $this->data($response);
        $rows = $data['billers'] ?? null;

        if (! is_array($rows)) {
            throw new ProviderMalformedResponse('Billers payload missing.');
        }

        $billers = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['key'] ?? null) || ! is_string($row['label'] ?? null)
                || ! preg_match('/^[a-z0-9_]{1,40}$/', $row['key'])) {
                continue; // skip an unusable row rather than trust it
            }

            $billers[$row['key']] = new Biller(
                $row['key'],
                self::clean($row['label'], 100) ?? $row['key'],
                filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                self::clean((string) ($row['account_label'] ?? 'Account number'), 60) ?? 'Account number',
                filter_var($row['requires_phone'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ($row['lookup_by'] ?? 'account') === 'phone' ? 'phone' : 'account',
                filter_var($row['links_phone_to_account'] ?? false, FILTER_VALIDATE_BOOLEAN),
                filter_var($row['has_amount_due'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        }

        return new Catalog(
            $billers,
            self::decimalOrNull($data['min_amount'] ?? null),
            self::decimalOrNull($data['max_amount'] ?? null),
            is_string($data['currency'] ?? null) ? strtoupper(substr($data['currency'], 0, 3)) : 'GHS',
            time(),
        );
    }

    private function parseLookup(Response $response): LookupResult
    {
        $data = $this->data($response);

        $meters = [];
        foreach ((array) ($data['meters'] ?? []) as $meter) {
            if (! is_array($meter)) {
                throw new ProviderMalformedResponse('Meter entry malformed.');
            }
            $number = isset($meter['meterNumber']) ? trim((string) $meter['meterNumber']) : '';
            if ($number === '' || ! preg_match('/^[A-Za-z0-9-]{3,30}$/', $number)) {
                throw new ProviderMalformedResponse('Meter number malformed.');
            }
            $meters[] = [
                'name' => self::clean(isset($meter['name']) ? (string) $meter['name'] : null, 150),
                'meterNumber' => $number,
                'outstanding' => self::decimalOrNull($meter['outstanding'] ?? null),
            ];
        }

        $name = self::clean(isset($data['account_name']) ? (string) $data['account_name'] : null, 150);
        $number = self::clean(isset($data['account_number']) ? (string) $data['account_number'] : null, 40);

        if ($meters === [] && $name === null && $number === null) {
            throw new ProviderMalformedResponse('Lookup returned no account information.');
        }

        return new LookupResult(
            $name,
            $number,
            self::decimalOrNull($data['amount_due'] ?? null),
            self::clean(isset($data['bouquet']) ? (string) $data['bouquet'] : null, 100),
            $meters,
        );
    }

    private function parsePay(Response $response): PayResult
    {
        $data = $this->data($response);
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $reference)) {
            throw new ProviderMalformedResponse('Pay response carried no usable provider reference.');
        }

        return new PayResult(
            $reference,
            isset($data['order_id']) ? self::clean((string) $data['order_id'], 80) : null,
            ProviderStatusMapper::normalize(isset($data['status']) ? (string) $data['status'] : null),
            filter_var($data['already_processed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            self::decimalOrNull($data['commission_share_percent'] ?? null),
        );
    }

    private function parseStatus(Response $response): StatusResult
    {
        $data = $this->data($response);
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw new ProviderMalformedResponse('Status response carried no reference.');
        }

        return new StatusResult(
            $reference,
            ProviderStatusMapper::normalize(isset($data['status']) ? (string) $data['status'] : null),
            isset($data['payment_status']) ? self::clean((string) $data['payment_status'], 20) : null,
            isset($data['reason']) ? self::clean((string) $data['reason'], 255) : null,
            self::decimalOrNull($data['commission_earned'] ?? null),
            self::decimalOrNull($data['amount'] ?? null),
            isset($data['account_number']) ? self::clean((string) $data['account_number'], 40) : null,
        );
    }

    /** @return array<string,mixed> */
    private function data(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json) || ($json['success'] ?? null) !== true || ! is_array($json['data'] ?? null)) {
            throw new ProviderMalformedResponse('Unexpected response shape.', $response->status());
        }

        return $json['data'];
    }

    /** Provider text is untrusted display input: no markup, no control chars, bounded. */
    public static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return Money::toDecimalString($value);
    }
}
