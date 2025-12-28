<?php

namespace App\Jobs;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Services\RecipientNumberLoggingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LogRecipientNumberUsage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $sourceType,
        public int $sourceId
    ) {
    }

    public function handle(RecipientNumberLoggingService $logger): void
    {
        try {
            if (!$logger->enabled()) {
                return;
            }

            if ($this->sourceType === 'order') {
                $order = Order::query()->select([
                    'id',
                    'vendor_id',
                    'owner_vendor_id',
                    'reseller_vendor_id',
                    'is_reseller_order',
                    'recipient_phone_number',
                    'service_purchased',
                    'payment_completed_at',
                ])->find($this->sourceId);

                if (!$order) {
                    return;
                }

                $logger->logFromOrder($order);
                return;
            }

            if ($this->sourceType === 'afa_registration') {
                $registration = AfaRegistration::query()->select([
                    'id',
                    'vendor_id',
                    'reseller_vendor_id',
                    'is_reseller_order',
                    'phone_number',
                    'payment_completed_at',
                    'payment_status',
                ])->find($this->sourceId);

                if (!$registration) {
                    return;
                }

                $logger->logFromAfaRegistration($registration);
            }
        } catch (\Throwable $e) {
            Log::warning('Recipient number logging failed (ignored)', [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
