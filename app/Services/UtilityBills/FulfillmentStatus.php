<?php

namespace App\Services\UtilityBills;

/**
 * Utility Bill FULFILLMENT lifecycle. Deliberately a different axis from the
 * customer payment (`orders.payment_status` / `payment_integrity_status`): a
 * paid order whose bill could not be delivered stays paid, and is surfaced here
 * as a recoverable fulfillment problem — never as a failed customer payment.
 *
 *   awaiting_payment  no trusted payment yet; the provider has NOT been contacted
 *   queued            payment proven; waiting for the submit job
 *   submitting        a worker holds the claim and is calling POST /utilities/pay
 *   provider_pending  provider accepted the request (status "pending")
 *   provider_processing provider is working on it
 *   completed         provider reported completed — the ONLY commission-earning state
 *   failed            provider reported a definitive failure
 *   provider_refunded provider refunded XTRA4U's PROVIDER wallet (NOT a customer refund)
 *   attention         recoverable problem needing a human or a later retry
 *                     (provider wallet empty, provider/biller disabled, key rejected, ...)
 *   provider_unresolved the provider holds the order but gave no final status within the
 *                     automatic polling horizon; automatic polling stopped. NOT a failure:
 *                     payment, references and commission terms are kept, an admin
 *                     refresh still queries the SAME provider order, and a late
 *                     "completed" still finalises it (and pays commission once)
 */
final class FulfillmentStatus
{
    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const QUEUED = 'queued';

    public const SUBMITTING = 'submitting';

    public const PROVIDER_PENDING = 'provider_pending';

    public const PROVIDER_PROCESSING = 'provider_processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const PROVIDER_REFUNDED = 'provider_refunded';

    public const ATTENTION = 'attention';

    public const PROVIDER_UNRESOLVED = 'provider_unresolved';

    public const ALL = [
        self::AWAITING_PAYMENT, self::QUEUED, self::SUBMITTING, self::PROVIDER_PENDING,
        self::PROVIDER_PROCESSING, self::COMPLETED, self::FAILED, self::PROVIDER_REFUNDED,
        self::ATTENTION, self::PROVIDER_UNRESOLVED,
    ];

    /** Nothing more will happen to these automatically. */
    public const TERMINAL = [self::COMPLETED, self::FAILED, self::PROVIDER_REFUNDED];

    /** Provider holds the order; poll it automatically (within the polling horizon). */
    public const POLLABLE = [self::PROVIDER_PENDING, self::PROVIDER_PROCESSING];

    /** An admin may query the provider for these (automatic polling has stopped for unresolved). */
    public const REFRESHABLE = [self::PROVIDER_PENDING, self::PROVIDER_PROCESSING, self::PROVIDER_UNRESOLVED];

    /** Where the provider has (or may have) a live order for the current attempt. */
    public const IN_FLIGHT = [self::SUBMITTING, self::PROVIDER_PENDING, self::PROVIDER_PROCESSING, self::PROVIDER_UNRESOLVED];

    /**
     * Paid and still moving automatically, so a long wait is otherwise silent. ATTENTION is
     * excluded: entering it already alerts (or is covered by the one-per-incident wallet alert).
     */
    public const STUCK_ALERTABLE = [self::QUEUED, self::SUBMITTING, self::PROVIDER_PENDING, self::PROVIDER_PROCESSING];

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::AWAITING_PAYMENT => 'Awaiting payment',
            self::QUEUED => 'Payment received',
            self::SUBMITTING, self::PROVIDER_PENDING => 'Submitted to provider',
            self::PROVIDER_PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::PROVIDER_REFUNDED => 'Not completed',
            self::ATTENTION => 'Needs attention',
            self::PROVIDER_UNRESOLVED => 'Provider status unresolved',
            default => 'Unknown',
        };
    }
}
