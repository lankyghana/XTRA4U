<?php

namespace App\Support;

use App\Models\Order;

/**
 * The ONLY safe way for a gateway "failed" answer to mark an order failed.
 *
 * A browser verify, redirect callback or webhook reads the order, asks the
 * gateway (an HTTP call that can take seconds), then acts on the answer. In
 * that window another path — typically the success webhook — may settle the
 * order. Writing `failed` from the model read before the call would then
 * overwrite a PAID order: the customer's money is taken and the order is never
 * fulfilled.
 *
 * So the transition is a single conditional UPDATE whose WHERE clause is
 * re-evaluated by the database against the current row (InnoDB takes the row
 * lock and reads the latest committed version, waiting for a concurrent
 * completeOrder() transaction to commit first). It only applies while the
 * order is still genuinely unsettled:
 *
 *   - payment_status is not paid/completed,
 *   - payment_completed_at is not set,
 *   - no trusted payment proof is held (payment_integrity_status is not one
 *     of the settlement-allowed values; PaymentIntegrityGuard may have stamped
 *     VERIFIED a moment before completeOrder() marks it paid).
 *
 * Nothing else about the order is touched; a failed order stays recoverable
 * (a later trusted success still settles it through PaymentService).
 */
final class PaymentFailureTransition
{
    /** This call moved the order to failed. */
    public const FAILED = 'failed';

    /** The order is paid (settled meanwhile, or already was); it was not touched. */
    public const PAID = 'paid';

    /** Not applied for another reason: already failed, or trusted proof is held and completion is in flight. */
    public const KEPT = 'kept';

    public static function apply(int $orderId): string
    {
        $marked = Order::query()
            ->whereKey($orderId)
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhereNotIn('payment_status', ['paid', 'completed', 'failed']))
            ->whereNull('payment_completed_at')
            ->where(fn ($q) => $q->whereNull('payment_integrity_status')->orWhereNotIn('payment_integrity_status', PaymentIntegrity::SETTLEMENT_ALLOWED))
            ->update(['payment_status' => 'failed', 'status' => 'Failed', 'updated_at' => now()]);

        if ($marked === 1) {
            return self::FAILED;
        }

        $current = Order::query()->whereKey($orderId)->value('payment_status');

        return in_array($current, ['paid', 'completed'], true) ? self::PAID : self::KEPT;
    }
}
