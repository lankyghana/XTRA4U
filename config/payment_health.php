<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Health Monitoring Thresholds
    |--------------------------------------------------------------------------
    |
    | Purely display/alerting thresholds for the read-only admin Payment
    | Health page (App\Services\Admin\PaymentHealthService). None of these
    | values are read by payment initiation, verification, reconciliation,
    | or fulfillment — changing them can never affect financial behaviour,
    | only what gets highlighted for a human to look at.
    |
    */

    // A pending record younger than this is "fresh" — not shown as a concern.
    'pending_warning_minutes' => env('PAYMENT_HEALTH_PENDING_WARNING_MINUTES', 30),

    // A pending record older than this is a CRITICAL-bucket concern.
    'pending_critical_hours' => env('PAYMENT_HEALTH_PENDING_CRITICAL_HOURS', 24),

    // payments:reconcile not having finished successfully within this many
    // minutes is flagged CRITICAL — mirrors PaymentReconciliationService's
    // own 5-minute schedule with generous headroom for one missed tick.
    'stale_reconciliation_critical_minutes' => env('PAYMENT_HEALTH_STALE_RECONCILIATION_CRITICAL_MINUTES', 15),

    // A record is "repeated UNKNOWN" once its reconciliation_attempts count
    // reaches this without resolving — matches PaymentReconciliationService's
    // own backoff schedule tapering off around here.
    'repeated_unknown_attempts' => env('PAYMENT_HEALTH_REPEATED_UNKNOWN_ATTEMPTS', 3),

    // Manual-review backlog size (no_gateway + integrity_mismatch + parked
    // manual_review rows) that becomes a CRITICAL alert.
    'manual_review_backlog_critical' => env('PAYMENT_HEALTH_MANUAL_REVIEW_BACKLOG_CRITICAL', 20),

    // External fulfillment backlog (orders paid but not yet fulfilled) that
    // becomes a WARNING alert.
    'fulfillment_backlog_warning' => env('PAYMENT_HEALTH_FULFILLMENT_BACKLOG_WARNING', 20),

    // How many rows the manual review queue shows per page.
    'per_page' => env('PAYMENT_HEALTH_PER_PAGE', 25),
];
