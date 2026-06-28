<?php

namespace App\Services;

use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\NetworkService;
use App\Models\Transaction;
use App\Events\ResultCheckerOrderPaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResultCheckerService
{
    public function __construct(
        protected SmsService $smsService,
        protected WalletService $walletService,
    ) {}

    /**
     * Handle payment callback: mark order as paid and fulfill or queue for stock.
     *
     * Everything runs in ONE transaction. We call the *Unsafe helpers directly
     * instead of the public allocatePinsForOrder / releasePinsForOrder methods
     * so that we never open a nested DB::transaction (which requires savepoint
     * support and varies across DB drivers).
     */
    public function handlePaymentCallback(ResultCheckerOrder $order, string $paymentReference, string $gateway = null): void
    {
        $processed = DB::transaction(function () use ($order, $paymentReference, $gateway) {
            // Idempotent: if already paid, skip
            $order->refresh();
            if ($order->status === 'completed' || $order->paid_at) {
                Log::info('ResultCheckerService: Order already paid, skipping duplicate callback', [
                    'order_id'  => $order->id,
                    'reference' => $paymentReference,
                ]);
                return false;
            }

            // Mark order as paid
            $order->update([
                'paid_at'           => now(),
                'payment_reference' => $paymentReference,
                'payment_gateway'   => $gateway,
            ]);

            // Record the payment in the transactions table so it appears in
            // /admin/transactions alongside regular orders.
            Transaction::create([
                'vendor_id'            => $order->vendor_id,
                'amount'               => $order->total_price,
                'commission_amount'    => $order->total_price - $order->resolveVendorProfit(),
                'vendor_earning'       => $order->resolveVendorProfit(),
                'recipient_phone'      => $order->customer_phone,
                'payment_status'       => 'successful',
                'gateway_transaction_id' => $paymentReference,
                'timestamp'            => now(),
                'payment_type'         => 'result_checker',
                'transactionable_type' => ResultCheckerOrder::class,
                'transactionable_id'   => $order->id,
            ]);

            // Try to allocate stock immediately — call the Unsafe variant so we
            // stay inside the single outer transaction opened above.
            if ($this->doAllocatePins($order)) {
                $order->refresh();
            } else {
                // No stock available, queue for fulfillment when stock arrives
                $order->update(['status' => 'pending_stock']);
                Log::info('ResultCheckerService: Insufficient stock, order marked pending', [
                    'order_id'   => $order->id,
                    'quantity'   => $order->quantity,
                    'service_id' => $order->service_id,
                ]);
            }

            return true;
        });

        if ($processed) {
            // Fire event outside the transaction (safe side effects: SMS, notifications)
            event(new ResultCheckerOrderPaid($order));
        }
    }

    /**
     * Allocate pins to an order with pessimistic locking to prevent overselling.
     *
     * Public entry point for standalone callers (jobs, recovery paths).
     * Wraps doAllocatePins() in its own transaction so it is always atomic
     * when called independently.
     *
     * Do NOT call this from inside an already-open DB::transaction — use
     * doAllocatePins() directly instead to avoid nesting.
     *
     * Returns true if allocation succeeded, false if insufficient stock.
     */
    public function allocatePinsForOrder(ResultCheckerOrder $order): bool
    {
        return DB::transaction(fn () => $this->doAllocatePins($order));
    }

    /**
     * Release any PINs allocated to an order back into available stock.
     *
     * Public entry point for standalone callers (admin markFailed, etc.).
     * Wraps doReleasePins() in its own transaction so it is always atomic
     * when called independently.
     *
     * Do NOT call this from inside an already-open DB::transaction — use
     * doReleasePins() directly instead to avoid nesting.
     *
     * Returns the number of pins released.
     */
    public function releasePinsForOrder(ResultCheckerOrder $order): int
    {
        return DB::transaction(fn () => $this->doReleasePins($order));
    }

    /**
     * Atomically mark an order as failed and release any allocated PINs back to stock.
     *
     * Acquires a row lock on the order before re-checking guards, preventing the TOCTOU
     * race where a concurrent payment webhook flips status or sms_sent_at between the
     * caller's guard check and the transaction commit.
     *
     * Returns ['ok' => true, 'pins_released' => int] on success,
     * or ['ok' => false, 'error' => string] when a guard blocks the action.
     */
    public function adminMarkFailed(ResultCheckerOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            $locked = ResultCheckerOrder::lockForUpdate()->findOrFail($order->id);

            if ($locked->status === 'completed') {
                return ['ok' => false, 'error' => 'Completed orders cannot be marked as failed.'];
            }

            if ($locked->sms_sent_at) {
                return [
                    'ok'    => false,
                    'error' => 'Cannot fail this order — the delivery SMS was already sent to the customer. The PINs cannot be returned to stock.',
                ];
            }

            $released = $this->doReleasePins($locked);
            $locked->update(['status' => 'failed']);

            Log::info('ResultCheckerService: Order marked failed by admin', [
                'order_id'      => $locked->id,
                'pins_released' => $released,
            ]);

            return ['ok' => true, 'pins_released' => $released];
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers — no transaction wrapper; callers must hold one already
    // -------------------------------------------------------------------------

    /**
     * Core pin-allocation logic. Must be called inside an active DB transaction.
     *
     * Pessimistic-locks available pins for the service, assigns them to the order,
     * marks the order completed, and credits the vendor wallet.
     *
     * Returns true on success, false when stock is insufficient.
     */
    private function doAllocatePins(ResultCheckerOrder $order): bool
    {
        // Lock the order row before reading status so a concurrent worker cannot
        // pass this idempotency check simultaneously and double-allocate pins.
        $order = ResultCheckerOrder::lockForUpdate()->find($order->id);
        if ($order->status === 'completed' || $order->pins()->exists()) {
            return true;
        }

        // Pessimistic lock: prevent concurrent allocations from picking the same pins
        $availablePins = ResultCheckerPin::forService($order->service_id)
            ->where('status', 'available')
            ->lockForUpdate()
            ->take($order->quantity)
            ->get();

        if ($availablePins->count() < $order->quantity) {
            Log::warning('ResultCheckerService: Insufficient available pins', [
                'order_id'   => $order->id,
                'requested'  => $order->quantity,
                'available'  => $availablePins->count(),
                'service_id' => $order->service_id,
            ]);
            return false;
        }

        // Allocate pins: mark as sold and link to order
        $pinsData = [];
        $availablePins->each(function ($pin) use ($order, &$pinsData) {
            $pin->update([
                'status'                  => 'sold',
                'result_checker_order_id' => $order->id,
                'sold_at'                 => now(),
            ]);
            $pinsData[] = [
                'pin'    => $pin->pin,
                'serial' => $pin->serial,
            ];
        });

        // Store delivered pins on the order and mark as completed
        $order->update([
            'delivered_pins_json' => $pinsData,
            'status'              => 'completed',
            'fulfilled_at'        => now(),
        ]);

        // Credit vendor wallet — ResultCheckerOrder::resolveVendorProfit() handles
        // the vendor_profit / legacy-fallback logic in one canonical place.
        $profit = $order->resolveVendorProfit();
        if ($profit > 0) {
            $this->walletService->creditVendor($order->vendor_id, (float) $profit, [
                'purpose'                  => 'order_earning',
                'result_checker_order_id'  => $order->id,
                'service_purchased'        => $order->service->name ?? 'Result Checker',
            ]);
        }

        Log::info('ResultCheckerService: Pins allocated successfully', [
            'order_id'      => $order->id,
            'quantity'      => $order->quantity,
            'vendor_profit' => $profit,
        ]);

        return true;
    }

    /**
     * Core pin-release logic. Must be called inside an active DB transaction.
     *
     * Resets any sold/reserved pins linked to this order back to available and
     * clears delivered_pins_json on the order.
     *
     * Returns the number of pins released.
     */
    private function doReleasePins(ResultCheckerOrder $order): int
    {
        // Lock the linked pins so a concurrent FulfillPendingOrdersJob cannot
        // pick them up while we are resetting them.
        $linkedPins = ResultCheckerPin::where('result_checker_order_id', $order->id)
            ->whereIn('status', ['sold', 'reserved'])
            ->lockForUpdate()
            ->get();

        if ($linkedPins->isEmpty()) {
            return 0;
        }

        ResultCheckerPin::whereIn('id', $linkedPins->pluck('id'))
            ->update([
                'status'                  => 'available',
                'result_checker_order_id' => null,
                'sold_at'                 => null,
            ]);

        // Wipe the snapshot of delivered pins stored on the order itself
        $order->update(['delivered_pins_json' => null]);

        $count = $linkedPins->count();

        Log::info('ResultCheckerService: Released pins back to stock', [
            'order_id'      => $order->id,
            'pins_released' => $count,
            'service_id'    => $order->service_id,
        ]);

        return $count;
    }

    /**
     * Attempt to fulfill an already-paid order (recovery path).
     */
    public function handlePaymentSuccess(ResultCheckerOrder $order): void
    {
        $order->refresh();
        if ($order->status === 'completed') {
            return;
        }

        if (! $order->paid_at) {
            return;
        }

        if ($this->allocatePinsForOrder($order)) {
            event(new ResultCheckerOrderPaid($order->refresh()));
        }
    }

    /**
     * Manual fulfillment by admin for pending_stock orders
     */
    public function fulfillPendingOrder(ResultCheckerOrder $order): array
    {
        if ($order->status !== 'pending_stock') {
            return [
                'success' => false,
                'message' => 'Order is not in pending_stock status (current: ' . $order->status . ')',
            ];
        }

        try {
            $allocated = $this->allocatePinsForOrder($order);

            if ($allocated) {
                // Fire event for SMS delivery
                event(new ResultCheckerOrderPaid($order->refresh()));
                return [
                    'success' => true,
                    'message' => 'Order fulfilled successfully',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Insufficient stock to fulfill order',
                ];
            }
        } catch (\Exception $e) {
            Log::error('ResultCheckerService: Error during fulfillment', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Error during fulfillment: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send SMS with PIN and SERIAL to customer
     */
    public function sendDeliveryNotification(ResultCheckerOrder $order): bool
    {
        if ($order->sms_sent_at) {
            return true;
        }

        if (!$order->delivered_pins_json || count($order->delivered_pins_json) === 0) {
            Log::warning('ResultCheckerService: No pins to send', ['order_id' => $order->id]);
            return false;
        }

        try {
            $order->load('service');
            $pins = $order->delivered_pins_json;
            $message = $this->formatSmsMessage($order, $pins);

            $sent = $this->smsService->send($order->customer_phone, $message);

            if ($sent) {
                $order->update(['sms_sent_at' => now()]);
                Log::info('ResultCheckerService: SMS sent successfully', [
                    'order_id' => $order->id,
                    'phone' => $order->customer_phone,
                ]);
                return true;
            } else {
                Log::warning('ResultCheckerService: SMS send returned false', [
                    'order_id' => $order->id,
                    'phone' => $order->customer_phone,
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('ResultCheckerService: Error sending SMS', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Format SMS message with PIN and SERIAL
     */
    protected function formatSmsMessage(ResultCheckerOrder $order, array $pins): string
    {
        $checker = $order->service->name ?? 'Result Checker';
        $pinList = implode(
            "\n",
            array_map(fn($p) => "PIN: {$p['pin']}\nSERIAL: {$p['serial']}", $pins)
        );

        return "Your {$checker} has been delivered.\n{$pinList}\n\nThank you for using XTRA4U.";
    }

    /**
     * Get order status for customer status checker (by reference, order ID, or phone).
     *
     * Lookup priority:
     *  1. Exact payment reference
     *  2. Numeric order ID
     *  3. Exact (normalised) phone number — only when the query looks like a phone number
     */
    public function getOrderStatus(string $query): ?array
    {
        $query = trim($query);

        // 1. Try by exact payment reference
        $order = ResultCheckerOrder::where('payment_reference', $query)->first();

        // 2. Try by numeric order ID (only when the query is a plain integer)
        if (!$order && ctype_digit($query)) {
            $order = ResultCheckerOrder::find((int) $query);
        }

        // 3. Try by exact phone number.
        //    Normalise both sides: strip spaces, dashes, dots, and parentheses so
        //    "024 123 4567", "024-123-4567", and "0241234567" all match.
        //    A leading-wildcard LIKE cannot use an index and exposes any customer's
        //    order to a partial-number search, so we use an exact match instead.
        if (!$order && $this->looksLikePhoneNumber($query)) {
            $normalised = $this->normalisePhone($query);
            $order = ResultCheckerOrder::whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '.', ''), '()', '') = ?",
                [$normalised]
            )
                ->latest()
                ->first();
        }

        if (!$order) {
            return null;
        }

        $order->load('service');
        return $this->formatOrderStatus($order);
    }

    /**
     * Format order status for customer display
     */
    protected function formatOrderStatus(ResultCheckerOrder $order): array
    {
        $response = [
            'id' => $order->id,
            'reference' => $order->payment_reference,
            'status' => $order->status,
            'service' => $order->service->name ?? 'Unknown',
            'customer_name' => $order->customer_name,
            'created_at' => $order->created_at->toIso8601String(),
        ];

        // Only show pins if order is completed and SMS has been sent
        if ($order->status === 'completed' && $order->sms_sent_at) {
            // Optionally show pins if customer has been notified
            $response['pins_delivered'] = true;
            // Don't expose raw pins in API (customer got them via SMS)
        }

        // Show relevant messaging
        $response['message'] = match ($order->status) {
            'pending_payment' => 'Awaiting payment confirmation',
            'pending_stock' => 'Waiting for stock availability',
            'processing' => 'Processing your order',
            'completed' => $order->sms_sent_at ? 'Delivered to your phone' : 'Ready for delivery',
            'failed' => 'Order could not be completed',
            default => 'Unknown status',
        };

        return $response;
    }

    /**
     * Strip common phone-number formatting characters for normalised comparison.
     * Removes spaces, dashes, dots, and parentheses so that:
     *   "024 123 4567", "024-123-4567", "024.123.4567" → "0241234567"
     */
    private function normalisePhone(string $phone): string
    {
        return preg_replace('/[\s\-\.\(\)]+/', '', $phone) ?? $phone;
    }

    /**
     * Return true when a query string looks like a phone number:
     * consists only of digits, spaces, dashes, dots, parentheses, and a
     * leading + sign, with at least 7 digits total.
     * This prevents a short numeric order ID (e.g. "42") from being
     * mistakenly tried as a phone number.
     */
    private function looksLikePhoneNumber(string $query): bool
    {
        // Allow +, digits, spaces, dashes, dots, parentheses
        if (! preg_match('/^\+?[\d\s\-\.\(\)]+$/', $query)) {
            return false;
        }

        // Require at least 7 digits (minimum local phone length)
        $digitsOnly = preg_replace('/\D/', '', $query);
        return strlen($digitsOnly ?? '') >= 7;
    }
}

