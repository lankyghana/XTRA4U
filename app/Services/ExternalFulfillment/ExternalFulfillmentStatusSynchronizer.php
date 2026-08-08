<?php

namespace App\Services\ExternalFulfillment;

use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a status reported by an external fulfillment provider to a local order.
 *
 * This is the single place where a provider's view of an order is turned into
 * local state, so webhooks and scheduled polling cannot drift apart. Callers
 * hand over the raw provider status string; mapping, idempotency, locking and
 * the local status transition all happen here.
 *
 * Deliberately narrow: it moves an order from Processing to Completed (or
 * records a failure) and syncs the matching transaction rows. Wallet credits,
 * commissions and the affiliate chain are settled at payment time by
 * PaymentService::completeOrder() and are never touched from here.
 */
class ExternalFulfillmentStatusSynchronizer
{
    /** Provider reported delivery and the order was completed. */
    public const OUTCOME_COMPLETED = 'completed';

    /** Provider reported delivery but auto-completion is switched off. */
    public const OUTCOME_DELIVERED_PENDING_CONFIRMATION = 'delivered_pending_confirmation';

    /** Provider explicitly failed the order. */
    public const OUTCOME_FAILED = 'failed';

    /** Provider says the order is still in flight. */
    public const OUTCOME_PROCESSING = 'processing';

    /** Nothing to do — already in this state. */
    public const OUTCOME_UNCHANGED = 'unchanged';

    /** Status not recognised, or order is in a state we must not touch. */
    public const OUTCOME_IGNORED = 'ignored';

    /**
     * Local order statuses that must never be overwritten by a provider callback.
     * Cancelled/Refunded carry financial meaning that a late webhook must not undo.
     */
    private const TERMINAL_LOCAL_STATUSES = ['Completed', 'Cancelled', 'Refunded'];

    /**
     * @param  array<string,mixed>  $context  Extra detail for the audit log (source, payload ids…).
     */
    public function apply(int $orderId, string $provider, string $rawStatus, array $context = []): string
    {
        $provider = strtolower(trim($provider));
        $normalizedRaw = strtolower(trim($rawStatus));
        $outcome = $this->mapStatus($provider, $normalizedRaw);

        $logContext = $context + [
            'order_id' => $orderId,
            'provider' => $provider,
            'provider_status' => $normalizedRaw,
        ];

        if ($outcome === null) {
            // Unknown vocabulary: record it and change nothing. Never guess.
            Log::warning('External fulfillment status not recognised; ignoring', $logContext);

            return self::OUTCOME_IGNORED;
        }

        $result = DB::transaction(function () use ($orderId, $provider, $normalizedRaw, $outcome, $logContext) {
            /** @var Order|null $order */
            $order = Order::whereKey($orderId)->lockForUpdate()->first();

            if (! $order) {
                Log::warning('External fulfillment status sync: order not found', $logContext);

                return self::OUTCOME_IGNORED;
            }

            // A provider callback must never resurrect or contradict an order
            // that has already reached a terminal local state.
            if (in_array($order->status, self::TERMINAL_LOCAL_STATUSES, true)) {
                return $order->status === 'Completed'
                    ? self::OUTCOME_UNCHANGED
                    : self::OUTCOME_IGNORED;
            }

            // Only paid orders may be advanced. An unpaid order reaching here
            // means the provider is reporting on something we never settled.
            if (! in_array($order->payment_status, ['paid', 'completed'], true)) {
                Log::warning('External fulfillment status sync: order is not paid; ignoring', $logContext + [
                    'payment_status' => $order->payment_status,
                ]);

                return self::OUTCOME_IGNORED;
            }

            return match ($outcome) {
                'delivered' => $this->applyDelivered($order, $provider, $logContext),
                'failed' => $this->applyFailed($order, $provider, $normalizedRaw, $logContext),
                default => $this->applyProcessing($order, $provider, $logContext),
            };
        });

        return $result;
    }

    /**
     * Provider confirmed delivery: complete the order and settle its transactions.
     */
    private function applyDelivered(Order $order, string $provider, array $logContext): string
    {
        $now = now();

        $updates = [
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_last_error' => null,
            'external_fulfillment_provider_used' => $order->external_fulfillment_provider_used ?: $provider,
            'external_fulfillment_completed_at' => $order->external_fulfillment_completed_at ?: $now,
            'external_fulfillment_delivered_at' => $order->external_fulfillment_delivered_at ?: $now,
        ];

        if (! $this->autoCompleteEnabled()) {
            // Record the delivery, but leave the order for the vendor to confirm.
            $this->writeOrder($order, $updates);

            Log::info('External fulfillment delivered; auto-completion disabled, awaiting vendor confirmation', $logContext);

            return self::OUTCOME_DELIVERED_PENDING_CONFIRMATION;
        }

        $updates['status'] = 'Completed';

        $this->writeOrder($order, $updates);

        // Mirrors the manual vendor completion path so both routes leave
        // identical data behind.
        Transaction::where('order_id', $order->id)->update([
            'payment_status' => 'completed',
        ]);

        Log::info('External fulfillment delivered; order completed automatically', $logContext);

        return self::OUTCOME_COMPLETED;
    }

    /**
     * Provider explicitly failed the order.
     *
     * The local order is left in Processing on purpose: it is paid for, and a
     * failed delivery is a support/refund decision for an admin, not something
     * to close automatically. Only the fulfillment fields are updated so the
     * failure surfaces in the vendor's "failed API orders" queue.
     */
    private function applyFailed(Order $order, string $provider, string $rawStatus, array $logContext): string
    {
        if ($order->external_fulfillment_status === 'failed') {
            return self::OUTCOME_UNCHANGED;
        }

        $this->writeOrder($order, [
            'external_fulfillment_status' => 'failed',
            'external_fulfillment_last_error' => sprintf('Provider reported status "%s".', $rawStatus),
            'external_fulfillment_provider_used' => $order->external_fulfillment_provider_used ?: $provider,
            'external_fulfillment_completed_at' => $order->external_fulfillment_completed_at ?: now(),
        ]);

        Log::warning('External fulfillment reported failure by provider', $logContext);

        return self::OUTCOME_FAILED;
    }

    /**
     * Still in flight. Recorded only so polling can tell "seen recently" from
     * "never heard of"; never downgrades an order that already succeeded.
     */
    private function applyProcessing(Order $order, string $provider, array $logContext): string
    {
        if ($order->external_fulfillment_status === 'succeeded') {
            return self::OUTCOME_UNCHANGED;
        }

        if ($order->external_fulfillment_status === 'processing') {
            return self::OUTCOME_UNCHANGED;
        }

        $this->writeOrder($order, [
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_provider_used' => $order->external_fulfillment_provider_used ?: $provider,
        ]);

        Log::info('External fulfillment still processing at provider', $logContext);

        return self::OUTCOME_PROCESSING;
    }

    /**
     * Write through the query builder.
     *
     * The external_fulfillment_* columns are intentionally excluded from
     * Order::$fillable, so Model::update() would silently discard them.
     */
    private function writeOrder(Order $order, array $updates): void
    {
        Order::whereKey($order->id)->update($updates);
    }

    /**
     * Translate a provider's own status string into one of: delivered, failed,
     * processing. Returns null when the status is not in the provider's
     * configured vocabulary.
     */
    private function mapStatus(string $provider, string $rawStatus): ?string
    {
        if ($rawStatus === '') {
            return null;
        }

        $map = (array) config("external_fulfillment.status_map.{$provider}", []);

        $mapped = $map[$rawStatus] ?? null;

        return in_array($mapped, ['delivered', 'failed', 'processing'], true) ? $mapped : null;
    }

    private function autoCompleteEnabled(): bool
    {
        return (bool) config('external_fulfillment.auto_complete_on_delivery', true);
    }
}
