<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\ProcessExternalFulfillment;
use App\Models\Setting;
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

        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = Setting::getGroupFresh('external_fulfillment');
        $enabled = filter_var($settings['external_fulfillment_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            return;
        }

        ProcessExternalFulfillment::dispatch((int) $order->id)->afterCommit();
    }
}
