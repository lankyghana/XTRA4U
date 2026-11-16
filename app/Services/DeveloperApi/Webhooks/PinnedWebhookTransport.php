<?php

namespace App\Services\DeveloperApi\Webhooks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Outbound webhook transport that defeats DNS rebinding.
 *
 * Validating a hostname and then letting the HTTP client resolve it AGAIN is
 * exploitable: an attacker's DNS can answer "public" to the check and
 * "169.254.169.254" to the connection. So the connection is forced to the IP
 * that was just validated, using libcurl's CURLOPT_RESOLVE ("host:port:ip"):
 * curl performs NO DNS lookup for that host, yet the request keeps the real
 * Host header, SNI, and TLS certificate verification against the hostname.
 *
 * Also enforced here:
 *  - redirects are never followed (a 3xx is a failed delivery, not a hop);
 *  - https-only protocol restriction at the curl level (also for redirects);
 *  - environment/system proxies are disabled (they would bypass the pin);
 *  - connect + total timeouts; response capped via progress callback and
 *    CURLOPT_MAXFILESIZE; the response body is never read or stored;
 *  - if libcurl isn't available the transport REFUSES to send (fail closed)
 *    rather than falling back to a transport that cannot pin.
 */
final class PinnedWebhookTransport implements WebhookTransport
{
    public function send(ValidatedDestination $destination, array $headers, string $body): TransportResult
    {
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new UnsafeWebhookDestination('transport_unavailable', 'A DNS-pinned transport is not available.');
        }

        $options = $this->options($destination);
        $started = microtime(true);

        try {
            $response = Http::withOptions($options)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($destination->url);
        } catch (ConnectionException $e) {
            return new TransportResult($this->classify($e->getMessage()), null, $this->ms($started), $this->classify($e->getMessage()));
        } catch (\Throwable) {
            return new TransportResult('error', null, $this->ms($started), 'error');
        }

        $status = $response->status();
        $outcome = match (true) {
            $status >= 200 && $status < 300 => 'success',
            $status >= 300 && $status < 400 => 'redirect_blocked',
            default => 'http_error',
        };

        return new TransportResult($outcome, $status, $this->ms($started), $outcome === 'success' ? null : $outcome);
    }

    /**
     * The exact Guzzle/curl options. Public so tests can assert the pin without a network.
     *
     * @return array<string,mixed>
     */
    public function options(ValidatedDestination $d): array
    {
        $maxBytes = (int) config('developer_api.webhooks.max_response_bytes', 65536);
        $ip = str_contains($d->pinnedIp, ':') ? '['.$d->pinnedIp.']' : $d->pinnedIp;

        return [
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'proxy' => '',
            'timeout' => (float) config('developer_api.webhooks.timeout_seconds', 10),
            'connect_timeout' => (float) config('developer_api.webhooks.connect_timeout_seconds', 5),
            'curl' => [
                CURLOPT_RESOLVE => ["{$d->host}:{$d->port}:{$ip}"],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_MAXFILESIZE => $maxBytes,
                CURLOPT_NOPROGRESS => false,
                // Abort once more than $maxBytes have been received (covers chunked bodies with no Content-Length).
                CURLOPT_PROGRESSFUNCTION => static fn ($ch, $dlTotal, $dlNow) => $dlNow > $maxBytes ? 1 : 0,
            ],
        ];
    }

    private function classify(string $message): string
    {
        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'timed out'), str_contains($m, 'timeout') => 'timeout',
            str_contains($m, 'ssl'), str_contains($m, 'certificate') => 'tls_error',
            str_contains($m, 'callback'), str_contains($m, 'maximum file size') => 'too_large',
            default => 'connection_failed',
        };
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
