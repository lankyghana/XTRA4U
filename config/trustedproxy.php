<?php

use App\Support\TrustedProxies;

/*
| Trusted reverse proxies (read by Illuminate\Http\Middleware\TrustProxies).
|
| TRUSTED_PROXIES is a comma-separated list of the IP addresses / CIDR ranges of the proxies or load balancers that sit
| directly in front of this application. ONLY requests that arrive from one of them have their X-Forwarded-For / -Proto /
| -Host / -Port headers believed. Empty (the default) means "no proxy is trusted": the connecting address is the client.
|
| Never use a wildcard: `*`, `**`, `REMOTE_ADDR` and ranges wider than /8 (IPv4) or /16 (IPv6) are rejected and ignored.
| No production proxy address is shipped or guessed; the operator must supply them (docs/ai/06, section 9).
*/
$parsed = TrustedProxies::parse(env('TRUSTED_PROXIES'));

return [
    'proxies' => $parsed['proxies'],
    // How many entries were dropped as invalid (reported by `php artisan ai:preflight`); the entries themselves are never kept.
    'rejected' => $parsed['rejected'],
];
