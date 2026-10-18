<?php

namespace App\Listeners;

use App\Events\ResultCheckerOrderPaid;
use App\Services\ResultCheckerService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendResultCheckerDeliveryNotification implements ShouldQueue
{
    public function __construct(protected ResultCheckerService $resultCheckerService)
    {
    }

    public function handle(ResultCheckerOrderPaid $event): void
    {
        $order = $event->order->refresh();

        // Paid but waiting for PIN stock: reassure the customer once.
        if ($order->status === 'pending_stock') {
            $this->resultCheckerService->sendPendingStockNotification($order);

            return;
        }

        // Only send if order is completed and SMS has not been sent yet
        if ($order->status !== 'completed' || $order->sms_sent_at) {
            return;
        }

        $this->resultCheckerService->sendDeliveryNotification($order);
    }
}
