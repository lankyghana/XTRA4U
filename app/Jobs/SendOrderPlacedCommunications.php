<?php

namespace App\Jobs;

use App\Mail\OrderPlacedMail;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderPlacedCommunications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $orderId,
        public int $vendorId,
        public string $role,
        public float $earning,
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $lockKey = sprintf('lock:order-comm:%d:%d:%s', $this->orderId, $this->vendorId, $this->role);

        $lock = Cache::lock($lockKey, 600);
        if (! $lock->get()) {
            return;
        }

        try {
            $order = Order::query()->find($this->orderId);
            $vendor = Vendor::query()->find($this->vendorId);

            if (! $order || ! $vendor) {
                return;
            }

            if (! empty($vendor->email)) {
                try {
                    Mail::to($vendor->email)->send(new OrderPlacedMail($order, $vendor, $this->role, $this->earning));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send order email (job)', [
                        'order_id' => $this->orderId,
                        'vendor_id' => $this->vendorId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } finally {
            optional($lock)->release();
        }
    }
}
