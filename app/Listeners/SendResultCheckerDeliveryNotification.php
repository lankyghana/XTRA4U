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

        // Only send if order is completed and SMS has not been sent yet
        if ($order->status !== 'completed' || $order->sms_sent_at) {
            return;
        }

        $this->resultCheckerService->sendDeliveryNotification($order);
    }
}
