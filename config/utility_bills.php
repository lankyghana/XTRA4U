<?php

return [
    // Provider catalog (GET /utilities/billers) cache. Deliberately short: the
    // provider (or its admin) can disable a biller at any time and availability
    // must never be cached for long. A failed fetch falls back to the last good
    // catalog for `catalog_stale_ttl` seconds, but only for DISPLAY; a sale is
    // still re-checked against a fresh-or-stale-but-recent catalog and never
    // proceeds when the provider is unreachable and the stale copy has expired.
    'catalog_ttl' => (int) env('UTILITY_BILLS_CATALOG_TTL', 120),
    'catalog_stale_ttl' => (int) env('UTILITY_BILLS_CATALOG_STALE_TTL', 900),

    // Identical lookups (same biller/account/phone) are served from cache for
    // this long. Lookup is the tightest provider limit (10/min per key, shared
    // by every customer), so this is what keeps normal traffic inside it.
    'lookup_cache_ttl' => (int) env('UTILITY_BILLS_LOOKUP_CACHE_TTL', 120),

    // How long a verified lookup stays usable to create an order.
    'lookup_token_ttl' => (int) env('UTILITY_BILLS_LOOKUP_TOKEN_TTL', 900),

    // Our own budgets, set a notch BELOW the provider's published per-minute
    // limits (lookup 10, pay 6, status 30, billers 30) so normal traffic never
    // earns a 429.
    'rate' => [
        'lookup_per_minute' => (int) env('UTILITY_BILLS_LOOKUP_PER_MINUTE', 9),
        'pay_per_minute' => (int) env('UTILITY_BILLS_PAY_PER_MINUTE', 5),
        'status_per_minute' => (int) env('UTILITY_BILLS_STATUS_PER_MINUTE', 25),
        'billers_per_minute' => (int) env('UTILITY_BILLS_BILLERS_PER_MINUTE', 25),
    ],

    // Fulfillment.
    'claim_stale_seconds' => (int) env('UTILITY_BILLS_CLAIM_STALE_SECONDS', 300),
    'max_submit_attempts' => (int) env('UTILITY_BILLS_MAX_SUBMIT_ATTEMPTS', 8),

    // Status polling backoff (seconds between checks, by number of checks so
    // far; the last value repeats). Terminal orders are never polled.
    'status_backoff' => [20, 45, 90, 180, 300, 600, 1200],

    // After this long without a terminal status the order is surfaced for
    // human attention (never auto-failed and never auto-refunded).
    'status_attention_after_minutes' => (int) env('UTILITY_BILLS_ATTENTION_AFTER_MINUTES', 120),
];
