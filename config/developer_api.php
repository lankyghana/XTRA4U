<?php

return [
    'version' => 'v1',

    // Scopes an API key may carry. Anything not listed here can never be granted.
    'scopes' => [
        'services:read' => 'List supported networks and service categories',
        'products:read' => 'Browse the product catalog and offer details',
        'quotes:create' => 'Create price quotes',
        'orders:create' => 'Create orders from quotes',
        'orders:read' => 'Read orders, order status and transactions of the application',
        'payments:initiate' => 'Initiate payment for an order',
        'webhooks:manage' => 'Manage webhook endpoints',
        'ai:chat' => 'Use the XTRA4U AI assistant',
        'sandbox:simulate' => 'Simulate order/payment outcomes in the sandbox (test keys only)',
    ],

    // Scopes that exist ONLY in the sandbox. ApiKeyService refuses to put them on a production key, and
    // DeveloperApiKey::hasScope() ignores them on any non-sandbox key even if one were written to the DB by hand.
    'sandbox_only_scopes' => ['sandbox:simulate'],

    // Product categories (see config/storefront.php) the public API exposes.
    // Result checkers, AFA registration and utility bills have their own flows and
    // tables and are intentionally NOT part of API v1.
    'exposed_categories' => ['data'],

    // Per-application limit resolves as: application override -> plan -> default below.
    'rate_limit' => [
        'default_per_minute' => (int) env('DEVELOPER_API_RATE_LIMIT', 60),
        // Failed-authentication attempts per IP per minute (brute-force guard).
        'auth_failures_per_minute' => (int) env('DEVELOPER_API_AUTH_FAILURES_PER_MINUTE', 20),
        'health_per_minute' => 60,
    ],

    'quote' => [
        'ttl_minutes' => (int) env('DEVELOPER_API_QUOTE_TTL_MINUTES', 10),
    ],

    'keys' => [
        'default_expiry_days' => 365,
        'max_active_per_application' => 5,
    ],

    'applications' => [
        'max_per_vendor' => 10,
    ],

    'webhooks' => [
        // Real, observable order/payment states only (see OrderEventDetector). Nothing else may be subscribed to.
        'events' => [
            'order.created', 'order.processing', 'order.completed', 'order.failed',
            'payment.pending', 'payment.succeeded', 'payment.failed',
        ],
        'max_per_application' => 5,
        'allowed_ports' => [443],
        'timeout_seconds' => 10,
        'connect_timeout_seconds' => 5,
        'max_response_bytes' => 65536,
        // Seconds to wait after failed attempt N (index N-1). Attempts per round = count + 1.
        'backoff_seconds' => [60, 300, 1800, 7200, 21600],
        // A delivery claimed longer ago than this is presumed orphaned by a dead worker and re-claimable.
        'claim_timeout_seconds' => 300,
        // The sweeper watches orders created within this many days (a failed order may still be paid later).
        'detect_window_days' => 14,
        'sweep_batch' => 200,
    ],

    // Sandbox (xt_test_ keys). Everything here is simulated and stored in dedicated developer_sandbox_* tables.
    'sandbox' => [
        // Automatic cleanup is OFF unless a retention is configured explicitly (null = keep everything).
        'retention_days' => env('DEVELOPER_API_SANDBOX_RETENTION_DAYS') ? (int) env('DEVELOPER_API_SANDBOX_RETENTION_DAYS') : null,
        // Safety floor: cleanup never deletes anything younger than this, whatever is configured or passed.
        'min_retention_days' => 7,
        'prune_batch' => 500,
    ],

    // Throttle for live gateway verification triggered by order-status reads.
    'verify_throttle_seconds' => 10,

    // Request log retention in days.
    'log_retention_days' => 30,
];
