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
    ],

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
        'max_per_developer' => 10,
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

    // Throttle for live gateway verification triggered by order-status reads.
    'verify_throttle_seconds' => 10,

    // Request log retention in days.
    'log_retention_days' => 30,
];
