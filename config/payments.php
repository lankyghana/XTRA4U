<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform currency
    |--------------------------------------------------------------------------
    |
    | XTRA4U is single-currency (Ghana cedi). This is the currency frozen onto
    | every order at creation and the one a gateway-confirmed payment must
    | match. Stated here once rather than as a literal scattered through the
    | payment pipeline.
    |
    */
    'currency' => env('PAYMENTS_CURRENCY', 'GHS'),

    /*
    |--------------------------------------------------------------------------
    | Legacy expected-amount reconstruction
    |--------------------------------------------------------------------------
    |
    | Orders created before this hardening carry no immutable
    | `expected_amount`. The question is whether such an order's financial
    | terms can still be established well enough to verify a payment against.
    |
    | An earlier version of this system answered that with a DATE CUTOFF —
    | "orders created after the server-authoritative pricing fix (2026-09-12)
    | may trust their own amount_paid". That assumption was withdrawn as
    | unsound. It proves only that the RECORDED EXPECTATION was server-derived,
    | which is a different claim from THE MONEY ARRIVED: the public /purchase
    | wallet bypass (present from 2026-02-05 until this hardening) produced
    | orders with a perfectly correct server-derived amount_paid and no payment
    | at all. A date is not evidence about an individual order.
    |
    | What replaces it is INDEPENDENT CORROBORATION, decided per order:
    | a legacy order's expected amount is accepted only when the pricing row it
    | actually points at still agrees with the amount recorded on the order,
    | AND that row demonstrably has not been edited since the order was created
    | (products/reseller_products.updated_at <= orders.created_at). Two
    | independent records agreeing, plus proof the price did not move in
    | between, is historical evidence. Today's price on its own never is — an
    | order whose product has been re-priced since is NOT reconstructed, it is
    | parked for manual review.
    |
    | Set this to false to disable reconstruction entirely (maximum strictness:
    | every order without a frozen snapshot requires a human).
    |
    */
    'reconstruct_legacy_expected_amount' => env('PAYMENTS_RECONSTRUCT_LEGACY_EXPECTED_AMOUNT', true),

    /*
    |--------------------------------------------------------------------------
    | Integrity anomaly notifications
    |--------------------------------------------------------------------------
    |
    | A failed integrity check always produces a structured log entry. It also
    | raises an admin notification, throttled per order+reason so a retrying
    | webhook or a reconciliation backoff schedule cannot spam the dashboard
    | with the same finding.
    |
    */
    'integrity_alerts' => [
        'enabled' => env('PAYMENT_INTEGRITY_ALERTS_ENABLED', true),
        'throttle_minutes' => env('PAYMENT_INTEGRITY_ALERT_THROTTLE_MINUTES', 60),
    ],
];
