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

    // The catalog is read while every vendor storefront renders, so a slow or
    // down provider must not hold pages: display reads use a short timeout and
    // a failure is remembered (no further requests) for `catalog_failure_ttl`.
    'display_timeout' => (int) env('UTILITY_BILLS_DISPLAY_TIMEOUT', 2),
    'catalog_failure_ttl' => (int) env('UTILITY_BILLS_CATALOG_FAILURE_TTL', 60),

    // Customer-facing lookup limits. The provider budget below is shared by
    // every customer on every store, so no single visitor may take most of it.
    // Per IP is looser than per session because many mobile customers share a
    // carrier IP.
    'lookup_limit' => [
        'per_session_per_minute' => (int) env('UTILITY_BILLS_LOOKUPS_PER_SESSION', 3),
        'per_ip_per_minute' => (int) env('UTILITY_BILLS_LOOKUPS_PER_IP', 6),
    ],

    // Identical lookups (same biller/account/phone) are served from cache for
    // this long. Lookup is the tightest provider limit (10/min per key, shared
    // by every customer), so this is what keeps normal traffic inside it.
    'lookup_cache_ttl' => (int) env('UTILITY_BILLS_LOOKUP_CACHE_TTL', 120),

    // How long a verified lookup stays usable to create an order.
    'lookup_token_ttl' => (int) env('UTILITY_BILLS_LOOKUP_TOKEN_TTL', 900),

    // Our own budgets, set a notch BELOW the provider's published per-minute
    // limits (lookup 10, pay 6, status 30, billers 30) so normal traffic never
    // earns a 429. Enforced as a shared sliding 60 s window across every
    // process (ProviderRateBudget). The pay budget IS the bill throughput:
    // 5/min = at most 300 bills/hour. Raise UTILITY_BILLS_PAY_PER_MINUTE only
    // after KiNG FLEXY confirms a higher limit in writing; nothing else changes.
    'rate' => [
        'lookup_per_minute' => (int) env('UTILITY_BILLS_LOOKUP_PER_MINUTE', 9),
        'pay_per_minute' => (int) env('UTILITY_BILLS_PAY_PER_MINUTE', 5),
        'status_per_minute' => (int) env('UTILITY_BILLS_STATUS_PER_MINUTE', 25),
        'billers_per_minute' => (int) env('UTILITY_BILLS_BILLERS_PER_MINUTE', 25),
    ],

    // The provider's own documented per-key limits, for display only (Admin
    // shows them next to our budget). Never used to send faster.
    'provider_documented_rate' => ['lookup' => 10, 'pay' => 6, 'status' => 30, 'billers' => 30],

    // A submit job (queue worker only, never a web request) that finds the pay
    // budget full but freeing within this many seconds waits for that slot
    // instead of leaving the order for the next per-minute sweep. 0 disables.
    'pay_inline_wait_seconds' => (int) env('UTILITY_BILLS_PAY_INLINE_WAIT', 15),

    // Admin pipeline panel: a submission backlog that would take longer than
    // this to drain at the pay budget is shown as "accumulating".
    'backlog_warn_minutes' => (int) env('UTILITY_BILLS_BACKLOG_WARN_MINUTES', 10),

    // Fulfillment.
    'claim_stale_seconds' => (int) env('UTILITY_BILLS_CLAIM_STALE_SECONDS', 300),
    'max_submit_attempts' => (int) env('UTILITY_BILLS_MAX_SUBMIT_ATTEMPTS', 8),

    // Status polling backoff (seconds between checks, by number of checks so
    // far; the last value repeats). Frequent right after submission, then
    // progressively rarer. Terminal orders are never polled.
    'status_backoff' => [20, 45, 90, 180, 300, 600, 1200, 1800, 3600],

    // Automatic polling is BOUNDED: after this many automatic checks, or this
    // many hours since polling started, whichever comes first, the order moves
    // to provider_unresolved and is no longer polled automatically. With the
    // backoff above, 30 checks span about 23 hours (8 checks in the first
    // ~70 minutes, then hourly). This is never a failure: the bill is not
    // failed, refunded or re-sent, and an admin refresh still queries the
    // same provider order.
    'status_poll_max_checks' => (int) env('UTILITY_BILLS_STATUS_POLL_MAX_CHECKS', 30),
    'status_poll_max_hours' => (int) env('UTILITY_BILLS_STATUS_POLL_MAX_HOURS', 24),

    // Provider-wide problems are grouped into one incident per issue (see
    // UtilityBillIncidents): one alert when it starts, at most one "still
    // ongoing" reminder per this many minutes while it keeps happening, and one
    // "recovered" notice.
    'incident_realert_minutes' => (int) env('UTILITY_BILLS_INCIDENT_REALERT_MINUTES', 60),

    // After this long without a terminal status the order is surfaced for
    // human attention (never auto-failed and never auto-refunded).
    'status_attention_after_minutes' => (int) env('UTILITY_BILLS_ATTENTION_AFTER_MINUTES', 120),
];
