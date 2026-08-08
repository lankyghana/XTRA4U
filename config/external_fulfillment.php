<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auto-complete on provider delivery
    |--------------------------------------------------------------------------
    |
    | When true, a provider reporting an order as delivered moves the local
    | order from "Processing" to "Completed" automatically instead of waiting
    | for a vendor to press the manual confirmation button.
    |
    | This is a kill switch: set EXTERNAL_FULFILLMENT_AUTO_COMPLETE=false to
    | fall back to manual confirmation without a code deploy. Turning it off
    | does not lose anything — provider statuses are still recorded, orders
    | simply wait for the vendor as they did before.
    |
    */
    'auto_complete_on_delivery' => env('EXTERNAL_FULFILLMENT_AUTO_COMPLETE', true),

    /*
    |--------------------------------------------------------------------------
    | Status polling
    |--------------------------------------------------------------------------
    |
    | Providers that expose a status endpoint are polled on a schedule as a
    | safety net for missed/undelivered webhooks. Only providers listed in
    | 'pollable' are contacted; the rest rely on webhooks or manual completion.
    |
    */
    'polling' => [
        'enabled' => env('EXTERNAL_FULFILLMENT_POLLING_ENABLED', true),

        // Providers whose client implements SupportsStatusPolling.
        'pollable' => ['gigshub', 'skdataplug'],

        // Don't re-check the same order more often than this.
        'min_recheck_minutes' => 10,

        // Ignore orders older than this — a provider that has said nothing in
        // 7 days will not start now, and we avoid scanning old rows forever.
        'max_age_hours' => 168,

        // Safety valve on how many orders one run may check per vendor.
        'batch_size' => 100,
    ],

    'providers' => [
        'datafyhub' => [
            'networks' => ['mtn', 'telecel', 'ishare', 'bigtime', 'xpress'],
        ],
        'xpresportal' => [
            'networks' => ['MTN', 'TELECEL', 'AIRTELTIGO'],
        ],
        'gigshub' => [
            'networks' => ['mtn', 'telecel', 'at'],
        ],
        'skdataplug' => [
            'networks' => ['MTN', 'TELECEL', 'AIRTELTIGO'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider status vocabulary
    |--------------------------------------------------------------------------
    |
    | Maps a raw provider status string (lower-cased) onto one of three
    | internal outcomes:
    |
    |   delivered  -> order was fulfilled; complete it
    |   failed     -> provider explicitly gave up on the order
    |   processing -> still in flight; record but take no further action
    |
    | Anything not listed here is deliberately ignored (logged, no write), so
    | an unrecognised or newly-introduced provider status can never silently
    | complete or fail a real order. Add new strings here rather than in code.
    |
    */
    'status_map' => [
        'gigshub' => [
            'delivered' => 'delivered',
            'resolved' => 'delivered',
            'completed' => 'delivered',
            'success' => 'delivered',
            'successful' => 'delivered',
            'failed' => 'failed',
            'cancelled' => 'failed',
            'canceled' => 'failed',
            'refunded' => 'failed',
            'pending' => 'processing',
            'processing' => 'processing',
        ],

        'skdataplug' => [
            'delivered' => 'delivered',
            'completed' => 'delivered',
            'success' => 'delivered',
            'failed' => 'failed',
            'cancelled' => 'failed',
            'canceled' => 'failed',
            'refunded' => 'failed',
            'pending' => 'processing',
            'processing' => 'processing',
        ],

        // No status endpoint or webhook is documented for these two yet. The
        // maps are here so that adding one later is configuration, not code.
        'xpresportal' => [
            'delivered' => 'delivered',
            'completed' => 'delivered',
            'success' => 'delivered',
            'failed' => 'failed',
            'error' => 'failed',
            'cancelled' => 'failed',
            'rejected' => 'failed',
            'pending' => 'processing',
            'processing' => 'processing',
            'queued' => 'processing',
            'in_progress' => 'processing',
        ],

        'datafyhub' => [
            'delivered' => 'delivered',
            'completed' => 'delivered',
            'success' => 'delivered',
            'failed' => 'failed',
            'error' => 'failed',
            'pending' => 'processing',
            'processing' => 'processing',
        ],
    ],
];
