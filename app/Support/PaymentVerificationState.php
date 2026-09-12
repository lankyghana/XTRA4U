<?php

namespace App\Support;

/**
 * Turns a raw gateway verification result (the {success, message, data}
 * shape every *PaymentService::verifyPayment() already returns) into one of
 * four explicit states, without ever inferring financial state from an
 * English message string.
 *
 * Background (see the payment-reconciliation audit): every verifyPayment()
 * implementation collapses network/timeout exceptions, HTTP 5xx responses,
 * and malformed bodies into the same generic {success: false, message:
 * 'Error verifying payment.'} shape. Several callers used to `str_contains`
 * that message for the words "error"/"failed" to decide the payment had
 * failed — which means a pure network hiccup was being reported to the
 * customer as a failed payment, even though the charge may well have
 * succeeded at the gateway. This class is the single place that policy is
 * decided, so every caller applies the same rule:
 *
 *   SUCCESS: the provider authoritatively confirmed the payment succeeded.
 *   FAILED:  the provider authoritatively confirmed a terminal failure/
 *            decline/cancellation. This is the ONLY state that may cause a
 *            local record to be marked failed.
 *   PENDING: the provider explicitly reported the payment is still pending/
 *            processing/initiated.
 *   UNKNOWN: the verify call itself did not return an authoritative
 *            reading — network/timeout exception, HTTP 5xx, malformed or
 *            unexpected response. This is NOT proof the customer wasn't
 *            charged and must never be treated as FAILED.
 *
 * For every customer-facing or local-state purpose, PENDING and UNKNOWN are
 * handled identically: the payment stays pending locally. They are kept as
 * distinct states (rather than collapsed into one) so a future
 * reconciliation service and its logs can tell "provider says wait" apart
 * from "we couldn't reach the provider at all".
 */
final class PaymentVerificationState
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';

    /**
     * Gateway-reported statuses (normalized, lowercased) that count as a
     * successful payment. Matches the set already used across the codebase's
     * callback/webhook controllers.
     */
    private const SUCCESS_STATUSES = ['success', 'successful', 'completed', 'paid'];

    /**
     * Gateway-reported statuses that count as an AUTHORITATIVE, terminal
     * failure — the provider is telling us the charge did not happen and
     * will never happen. Anything not in this list (including statuses we
     * don't recognize) is treated as still-pending, never as failed.
     *
     * Matches the terminal-failure list already used by
     * UssdSubscriptionPurchaseService::isTerminalFailure() and
     * ResultCheckerPaymentCallbackController's JSON branch.
     */
    private const TERMINAL_FAILURE_STATUSES = [
        'failed', 'error', 'cancelled', 'canceled', 'declined', 'abandoned', 'expired', 'reversed',
    ];

    /**
     * Resolve the normalized state for a verification result.
     */
    public static function from(array $verification): string
    {
        if (! ($verification['success'] ?? false)) {
            // The verify call itself did not return an authoritative reading.
            // Never infer FAILED from this — see class docblock.
            return self::UNKNOWN;
        }

        $status = strtolower((string) data_get($verification, 'data.status', ''));

        if (in_array($status, self::SUCCESS_STATUSES, true)) {
            return self::SUCCESS;
        }

        if (in_array($status, self::TERMINAL_FAILURE_STATUSES, true)) {
            return self::FAILED;
        }

        // Empty / 'pending' / 'processing' / 'initiated' / 'unknown' / any
        // status we don't recognize -> stays pending rather than assumed dead.
        return self::PENDING;
    }

    public static function isSuccess(array $verification): bool
    {
        return self::from($verification) === self::SUCCESS;
    }

    public static function isTerminalFailure(array $verification): bool
    {
        return self::from($verification) === self::FAILED;
    }

    /**
     * True for PENDING and UNKNOWN alike: "nothing authoritative yet, leave
     * the local record as pending and check again later."
     */
    public static function isUnresolved(array $verification): bool
    {
        $state = self::from($verification);

        return $state === self::PENDING || $state === self::UNKNOWN;
    }
}
