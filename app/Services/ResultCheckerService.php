<?php

namespace App\Services;

use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\NetworkService;
use App\Events\ResultCheckerOrderPaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResultCheckerService
{
    public function __construct(
        protected SmsService $smsService,
    ) {}

    /**
     * Handle payment callback: mark order as paid and fulfill or queue for stock
     */
    public function handlePaymentCallback(ResultCheckerOrder $order, string $paymentReference, string $gateway = null): void
    {
        DB::transaction(function () use ($order, $paymentReference, $gateway) {
            // Idempotent: if already paid, skip
            $order->refresh();
            if ($order->status === 'completed' || $order->paid_at) {
                Log::info('ResultCheckerService: Order already paid, skipping duplicate callback', [
                    'order_id' => $order->id,
                    'reference' => $paymentReference,
                ]);
                return;
            }

            // Mark order as paid
            $order->update([
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
                'payment_gateway' => $gateway,
            ]);

            // Try to allocate stock immediately
            if ($this->allocatePinsForOrder($order)) {
                // Stock allocated, order marked as completed
                $order->refresh();
            } else {
                // No stock available, mark as pending_stock
                $order->update(['status' => 'pending_stock']);
                Log::info('ResultCheckerService: Insufficient stock, order marked pending', [
                    'order_id' => $order->id,
                    'quantity' => $order->quantity,
                    'service_id' => $order->service_id,
                ]);
            }
        });

        // Fire event outside transaction (safe side effects)
        event(new ResultCheckerOrderPaid($order));
    }

    /**
     * Allocate pins to order with pessimistic locking to prevent overselling
     * Returns true if allocation succeeded, false if insufficient stock
     */
    public function allocatePinsForOrder(ResultCheckerOrder $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order->refresh();
            if ($order->status === 'completed' || $order->pins()->exists()) {
                return true;
            }

            // Pessimistic lock: lock available pins for this service
            $availablePins = ResultCheckerPin::forService($order->service_id)
                ->where('status', 'available')
                ->lockForUpdate()
                ->take($order->quantity)
                ->get();

            if ($availablePins->count() < $order->quantity) {
                Log::warning('ResultCheckerService: Insufficient available pins', [
                    'order_id' => $order->id,
                    'requested' => $order->quantity,
                    'available' => $availablePins->count(),
                    'service_id' => $order->service_id,
                ]);
                return false;
            }

            // Allocate pins: mark as sold and link to order
            $pinsData = [];
            $availablePins->each(function ($pin) use ($order, &$pinsData) {
                $pin->update([
                    'status' => 'sold',
                    'result_checker_order_id' => $order->id,
                    'sold_at' => now(),
                ]);
                $pinsData[] = [
                    'pin' => $pin->pin,
                    'serial' => $pin->serial,
                ];
            });

            // Store delivered pins in order and mark as completed
            $order->update([
                'delivered_pins_json' => $pinsData,
                'status' => 'completed',
                'fulfilled_at' => now(),
            ]);

            Log::info('ResultCheckerService: Pins allocated successfully', [
                'order_id' => $order->id,
                'quantity' => $order->quantity,
            ]);

            return true;
        });
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
            $this->sendDeliveryNotification($order->refresh());
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
     * Get order status for customer status checker (by reference, order ID, or phone)
     */
    public function getOrderStatus(string $query): ?array
    {
        // Try by payment reference first
        $order = ResultCheckerOrder::where('payment_reference', $query)->first();

        // Try by order ID
        if (!$order) {
            $order = ResultCheckerOrder::find($query);
        }

        // Try by phone number
        if (!$order) {
            $order = ResultCheckerOrder::where('customer_phone', 'LIKE', "%{$query}%")
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
}
