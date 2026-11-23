<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Parses the TRUSTED_PROXIES setting: the addresses of the reverse proxies / load balancers whose X-Forwarded-* headers
 * the application may believe. Anything else that sends those headers is a client, and a client can write anything.
 *
 * Rules (fail safe: when in doubt, trust nothing):
 *  - only literal IPv4/IPv6 addresses or CIDR ranges are accepted; no wildcard (`*`, `**`), no `REMOTE_ADDR` keyword
 *    (which means "whoever is calling"), no hostnames;
 *  - a range wider than /8 (IPv4) or /16 (IPv6) is refused as "effectively everyone" (so `0.0.0.0/0` and `::/0` cannot
 *    sneak in as a wildcard);
 *  - an entry that does not meet these rules is DROPPED (never half-trusted) and reported by `ai:preflight`;
 *  - an empty or unset value trusts no proxy: the connecting address is the client address.
 *
 * No production proxy address is built in or guessed anywhere.
 */
final class TrustedProxies
{
    private const MIN_PREFIX_V4 = 8;

    private const MIN_PREFIX_V6 = 16;

    /**
     * @return array{proxies:list<string>,rejected:int}
     */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return ['proxies' => [], 'rejected' => 0];
        }

        $proxies = [];
        $rejected = 0;

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (self::valid($entry)) {
                $proxies[] = $entry;
            } else {
                $rejected++;
            }
        }

        return ['proxies' => array_values(array_unique($proxies)), 'rejected' => $rejected];
    }

    public static function valid(string $entry): bool
    {
        $parts = explode('/', $entry);

        if (count($parts) > 2) {
            return false;
        }

        $address = $parts[0];
        $v4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $v4 && ! $v6) {
            return false;
        }

        if (count($parts) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,3}$/D', $parts[1]) !== 1) {
            return false;
        }

        $prefix = (int) $parts[1];
        $max = $v4 ? 32 : 128;
        $min = $v4 ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6;

        return $prefix >= $min && $prefix <= $max && IpUtils::checkIp($address, $entry) === true;
    }
}
