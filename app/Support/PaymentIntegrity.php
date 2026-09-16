<?php

namespace App\Support;

/**
 * The vocabulary for "has the server PROVEN that this exact order's immutable
 * financial terms were satisfied by a trusted payment source?"
 *
 * This is deliberately a separate axis from `orders.payment_status`. That
 * column answers "where is this order in its payment lifecycle" (unpaid ->
 * paid) and a great deal of existing UI, reporting and querying depends on
 * its current meaning. This column answers a different question — "what
 * proof do we hold?" — and is the value that settlement and external
 * fulfillment now gate on. Keeping them separate is what makes the change
 * backwards compatible: no existing payment_status semantics move.
 *
 * Trusted payment sources, and the status each produces:
 *   - an independently verified gateway response ....... VERIFIED
 *   - an atomic server-side vendor wallet debit ........ WALLET_VERIFIED
 *   - an authenticated admin's explicit confirmation ... ADMIN_CONFIRMED
 *
 * The browser is never a trusted source, and neither is "the gateway said
 * this reference succeeded" on its own.
 */
final class PaymentIntegrity
{
    /**
     * A gateway order has been created and its terms frozen, but no
     * verification has succeeded yet. This is the starting state for every
     * new gateway-paid order and is NOT sufficient for settlement.
     */
    public const PENDING_VERIFICATION = 'pending_verification';

    /**
     * The gateway independently confirmed SUCCESS for this order's own
     * reference, in the expected currency, for an amount exactly equal to the
     * order's frozen expected_amount, under the gateway the order was created
     * with, using a transaction not already consumed by another order.
     */
    public const VERIFIED = 'verified';

    /**
     * Paid by an atomic server-side wallet debit against a server-calculated
     * price. There is no external party to verify against — the debit itself
     * is the proof, and it happens in the same request under a row lock.
     */
    public const WALLET_VERIFIED = 'wallet_verified';

    /**
     * An authenticated admin explicitly confirmed this payment by hand (the
     * existing "confirm payment" escape hatch for provider outages). A human
     * is the trusted source; the action is attributed and audited rather than
     * silently treated as if a gateway had proven it.
     */
    public const ADMIN_CONFIRMED = 'admin_confirmed';

    /**
     * Verification produced an authoritative answer that CONTRADICTS the
     * order's frozen terms — wrong amount, wrong currency, wrong reference,
     * wrong gateway, or a gateway transaction already consumed elsewhere.
     * Terminal for automation: never settles, never fulfils, always needs a
     * human. The order's money fields are left exactly as they were.
     */
    public const MISMATCH = 'mismatch';

    /**
     * Integrity can neither be proven nor disproven — most importantly, a
     * historical order that predates server-authoritative pricing and
     * therefore has no trustworthy expected amount to compare against. Fails
     * CLOSED: never settles automatically, never fulfils automatically.
     */
    public const MANUAL_REVIEW = 'manual_review';

    /**
     * Backfill state: this order was ALREADY paid/completed before payment
     * integrity tracking existed. Its financial history is left untouched and
     * it remains eligible for fulfillment, so nothing legitimately in flight
     * at deploy time breaks. It is distinguishable from a strictly VERIFIED
     * order for auditing precisely because it is not proof of anything.
     */
    public const LEGACY_PAID = 'legacy_paid';

    /**
     * Backfill state: a historical order that was NOT paid at deploy time.
     * These are the #83-class records — old, unresolved, and revivable by
     * reconciliation. They may never be settled automatically; proving their
     * terms requires a human.
     */
    public const LEGACY_UNVERIFIED = 'legacy_unverified';

    /**
     * Statuses under which PaymentService may create financial side effects
     * (transactions, wallet credits, earnings) for an order.
     *
     * LEGACY_PAID is included only because such orders are, by definition,
     * already `payment_status = paid` — completeOrder() returns early on them
     * via its existing idempotency check, so including it changes no
     * behaviour for them; excluding it would instead risk breaking the
     * already-completed historical records this task must not disturb.
     */
    public const SETTLEMENT_ALLOWED = [
        self::VERIFIED,
        self::WALLET_VERIFIED,
        self::ADMIN_CONFIRMED,
        self::LEGACY_PAID,
    ];

    /**
     * Statuses under which an order may be submitted to an external
     * fulfillment provider (i.e. real goods may be delivered).
     *
     * Identical to SETTLEMENT_ALLOWED by design: there is exactly one bar,
     * and it is "the server proved a trusted source satisfied this order's
     * immutable terms". LEGACY_PAID keeps historical in-flight orders
     * deliverable at deploy time without re-submitting anything already
     * succeeded (ProcessExternalFulfillment's own guards handle that).
     */
    public const FULFILLMENT_ALLOWED = [
        self::VERIFIED,
        self::WALLET_VERIFIED,
        self::ADMIN_CONFIRMED,
        self::LEGACY_PAID,
    ];

    /** Terminal states that automation must never retry on its own. */
    public const REQUIRES_HUMAN = [
        self::MISMATCH,
        self::MANUAL_REVIEW,
        self::LEGACY_UNVERIFIED,
    ];

    public static function allowsSettlement(?string $status): bool
    {
        return $status !== null && in_array($status, self::SETTLEMENT_ALLOWED, true);
    }

    public static function allowsFulfillment(?string $status): bool
    {
        return $status !== null && in_array($status, self::FULFILLMENT_ALLOWED, true);
    }

    public static function requiresHuman(?string $status): bool
    {
        return $status !== null && in_array($status, self::REQUIRES_HUMAN, true);
    }
}
