<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\ProcessExternalFulfillment;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use Illuminate\Support\Facades\Schema;

class DispatchExternalFulfillmentFromOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;

        if (! $order) {
            return;
        }

        if (! in_array($order->payment_status, ['paid', 'completed'], true)) {
            return;
        }

        if (! Schema::hasTable('vendor_settings')) {
            return;
        }

        $targetVendorId = (int) ($order->owner_vendor_id ?: $order->vendor_id);
        if ($targetVendorId <= 0) {
            return;
        }

        $vendor = Vendor::query()->find($targetVendorId);
        if (! $vendor) {
            return;
        }

        $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);
        if (! $config->isReady()) {
            return;
        }

        ProcessExternalFulfillment::dispatch((int) $order->id);
    }
}
