<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\LogRecipientNumberUsage;
use App\Services\RecipientNumberLoggingService;

class LogRecipientNumberFromOrderCompleted
{
    public bool $afterCommit = true;

    public function handle(OrderCompleted $event, RecipientNumberLoggingService $logging): void
    {
        if (!$logging->enabled()) {
            return;
        }

        if (!$logging->shouldEnqueue()) {
            $logging->warnIfSyncQueue();
            return;
        }

        LogRecipientNumberUsage::dispatch('order', (int) $event->order->id)
            ->onQueue((string) config('audit.recipient_numbers.queue', 'audit'))
            ->afterCommit();
    }
}
