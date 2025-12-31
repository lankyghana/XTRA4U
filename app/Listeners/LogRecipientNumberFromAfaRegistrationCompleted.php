<?php

namespace App\Listeners;

use App\Events\AfaRegistrationCompleted;
use App\Jobs\LogRecipientNumberUsage;
use App\Services\RecipientNumberLoggingService;

class LogRecipientNumberFromAfaRegistrationCompleted
{
    public bool $afterCommit = true;

    public function __construct(private RecipientNumberLoggingService $logging)
    {
    }

    public function handle(AfaRegistrationCompleted $event): void
    {
        $logging = $this->logging;

        if (! $logging->enabled()) {
            return;
        }

        if (! $logging->shouldEnqueue()) {
            $logging->warnIfSyncQueue();
            return;
        }

        LogRecipientNumberUsage::dispatch('afa_registration', (int) $event->registrationId)
            ->onQueue((string) config('audit.recipient_numbers.queue', 'audit'))
            ->afterCommit();
    }
}
