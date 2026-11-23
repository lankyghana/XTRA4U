<?php

namespace App\Support;

/**
 * The part of a client address that identifies "one client" for rate limiting.
 *
 * IPv4: the whole address. IPv6: only the /64 network prefix, because one ordinary subscriber is handed an entire /64
 * (2^64 addresses) and could otherwise rotate through a fresh address per request and never meet a per-address limit.
 * An absent or unparseable address collapses into one shared bucket: that is stricter for everyone, never looser.
 *
 * The address itself is whatever `$request->ip()` returns, which is the connecting address unless the request came
 * through a proxy listed in TRUSTED_PROXIES (see App\Support\TrustedProxies).
 */
final class ClientIpKey
{
    public static function of(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? @inet_pton($ip) : false;

        if ($packed === false || strlen($packed) !== 16) {
            return 'unknown';
        }

        return 'v6:'.bin2hex(substr($packed, 0, 8));
    }
}
