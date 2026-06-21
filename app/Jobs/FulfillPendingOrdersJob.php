<?php

namespace App\Jobs;

use App\Models\ResultCheckerOrder;
use App\Services\ResultCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FulfillPendingOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $serviceId = null)
    {
    }

    public function handle(ResultCheckerService $resultCheckerService): void
    {
        $query = ResultCheckerOrder::query()
            ->where('status', 'pending_stock')
            ->orderBy('created_at');

        if ($this->serviceId) {
            $query->where('service_id', $this->serviceId);
        }

        $orders = $query->cursor();

        foreach ($orders as $order) {
            $resultCheckerService->handlePaymentSuccess($order);
        }
    }
}
