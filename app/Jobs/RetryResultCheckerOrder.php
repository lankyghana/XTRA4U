<?php

namespace App\Jobs;

use App\Models\ResultCheckerOrder;
use App\Services\ResultCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryResultCheckerOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $orderId)
    {
    }

    public function handle(ResultCheckerService $resultCheckerService): void
    {
        $order = ResultCheckerOrder::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        if ($order->status === 'completed') {
            return;
        }

        $resultCheckerService->handlePaymentSuccess($order);
    }
}
