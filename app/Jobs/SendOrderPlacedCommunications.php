<?php

namespace App\Jobs;

use App\Mail\OrderPlacedMail;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\SmsService;
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
        public string $smsType = 'order'
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SmsService $smsService): void
    {
        $lockKey = sprintf('lock:order-comm:%d:%d:%s:%s', $this->orderId, $this->vendorId, $this->role, $this->smsType);

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

            if (! empty($vendor->phone_number)) {
                try {
                    if ($this->smsType === 'affiliate') {
                        $message = "XTRA4U: Affiliate sale! {$order->service_purchased} sold by your reseller. Your earning: GHS " . number_format($this->earning, 2);
                    } else {
                        $message = "XTRA4U: New order! {$order->service_purchased}. Earning: GHS " . number_format($this->earning, 2) . ". Customer: {$order->recipient_phone_number}";
                    }

                    $smsService->send($vendor->phone_number, $message);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send order SMS (job)', [
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
