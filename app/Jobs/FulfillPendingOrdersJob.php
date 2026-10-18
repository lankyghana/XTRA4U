<?php

namespace App\Jobs;

use App\Models\ResultCheckerOrder;
use App\Services\ResultCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FulfillPendingOrdersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Release the per-service unique lock after 15 minutes even if a worker is
     * killed mid-run (the cron worker uses --stop-when-empty), so recovery for
     * that service cannot be blocked indefinitely.
     */
    public int $uniqueFor = 900;

    public function __construct(public ?int $serviceId = null)
    {
    }

    public function uniqueId(): string
    {
        return (string) ($this->serviceId ?? 'all');
    }

    public function handle(ResultCheckerService $resultCheckerService): void
    {
        $query = ResultCheckerOrder::query()
            ->with('service')
            ->where('status', 'pending_stock')
            // Never allocate PINs to an order whose payment is not verified.
            ->whereNotNull('paid_at')
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
