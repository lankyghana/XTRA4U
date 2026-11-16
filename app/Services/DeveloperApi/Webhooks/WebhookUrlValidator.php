<?php

namespace App\Services\DeveloperApi\Webhooks;

/**
 * SSRF gatekeeper for webhook destinations. Called at registration AND again
 * immediately before every delivery attempt.
 *
 * Rules (all must hold; any doubt = rejection):
 *  - https only; no credentials; no fragment tricks; sane length; no control chars
 *  - port 443 (or one in developer_api.webhooks.allowed_ports)
 *  - a DNS name, never an IP literal (this also kills decimal/hex/octal IPv4 forms,
 *    because every accepted name must end in a non-numeric label)
 *  - plain ASCII hostname (IDNs must be punycode, so what we validate is what is resolved)
 *  - not localhost / internal / metadata-style names
 *  - DNS must resolve, and EVERY resolved address must be public (IpGuard)
 *
 * The result pins the connection to one validated IP (see PinnedWebhookTransport).
 */
final class WebhookUrlValidator
{
    private const BLOCKED_SUFFIXES = [
        '.localhost', '.local', '.localdomain', '.internal', '.intranet', '.lan', '.home',
        '.corp', '.private', '.svc', '.cluster', '.test', '.example', '.invalid', '.onion',
        '.arpa',
    ];

    private const BLOCKED_HOSTS = [
        'localhost', 'metadata', 'metadata.google.internal', 'metadata.goog', 'instance-data',
        'kubernetes', 'kubernetes.default',
    ];

    public function __construct(private readonly DnsResolver $dns) {}

    /** @throws UnsafeWebhookDestination */
    public function validate(string $url): ValidatedDestination
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw $this->unsafe('invalid_url', 'The webhook URL is not valid.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw $this->unsafe('invalid_url', 'The webhook URL is not valid.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw $this->unsafe('https_required', 'Webhook URLs must use https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw $this->unsafe('credentials_in_url', 'Webhook URLs must not contain credentials.');
        }

        $port = $parts['port'] ?? 443;
        $allowed = array_map('intval', (array) config('developer_api.webhooks.allowed_ports', [443]));
        if (! in_array((int) $port, $allowed, true)) {
            throw $this->unsafe('port_not_allowed', 'This port is not allowed for webhooks.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (IpGuard::isIpLiteral($host) || str_starts_with($host, '[')) {
            throw $this->unsafe('ip_literal', 'Webhook URLs must use a domain name, not an IP address.');
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) || strlen($host) > 253) {
            throw $this->unsafe('invalid_host', 'The webhook host name is not valid.');
        }

        $labels = explode('.', $host);
        // A name whose last label has no letter (e.g. 2130706433, 0x7f.1, 127.1) is an IP in disguise.
        if (! preg_match('/[a-z]/', end($labels)) || preg_match('/^0x[0-9a-f]*$/', end($labels))) {
            throw $this->unsafe('ip_literal', 'Webhook URLs must use a domain name, not an IP address.');
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw $this->unsafe('blocked_host', 'This host is not allowed.');
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw $this->unsafe('blocked_host', 'This host is not allowed.');
            }
        }

        $ips = $this->dns->resolve($host);
        if ($ips === []) {
            throw $this->unsafe('dns_unresolvable', 'The webhook host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! IpGuard::isPublic($ip)) {
                // Deliberately generic: do not tell a caller which internal address a name pointed at.
                throw $this->unsafe('non_public_address', 'The webhook host resolves to a non-public address.');
            }
        }

        // Prefer IPv4 for the pin (broadest curl --resolve support); fall back to IPv6.
        $v4 = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return new ValidatedDestination(
            url: $url,
            host: $host,
            port: (int) $port,
            ips: $ips,
            pinnedIp: $v4[0] ?? $ips[0],
        );
    }

    private function unsafe(string $reason, string $message): UnsafeWebhookDestination
    {
        return new UnsafeWebhookDestination($reason, $message);
    }
}
