<?php

namespace App\Services\DeveloperApi\Webhooks;

use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns each persisted-but-not-yet-fanned-out event into one delivery row per
 * subscribed, active endpoint of the SAME application. Idempotent and
 * crash-safe: a delivery is unique per (webhook, event), and an event is only
 * marked fanned-out in the same transaction that creates its deliveries, so a
 * crash simply causes a clean re-run.
 *
 * Isolation: webhooks are selected by the event's own application_id AND environment, so an
 * event can never be routed to another application's endpoint, and a sandbox event can never reach a
 * production endpoint (or the reverse).
 */
class WebhookFanout
{
    /** @return int number of deliveries created */
    public function run(): int
    {
        $created = 0;

        DeveloperEvent::query()
            ->whereNull('fanned_out_at')
            ->orderBy('id')
            ->chunkById((int) config('developer_api.webhooks.sweep_batch', 200), function ($events) use (&$created) {
                foreach ($events as $event) {
                    $created += $this->fanOut($event);
                }
            });

        return $created;
    }

    public function fanOut(DeveloperEvent $event): int
    {
        $created = 0;

        DB::transaction(function () use ($event, &$created) {
            $webhooks = DeveloperWebhook::query()
                ->where('application_id', $event->application_id)
                ->where('environment', $event->environment)
                ->where('is_active', true)
                ->get()
                ->filter(fn (DeveloperWebhook $w) => $w->subscribesTo($event->type));

            foreach ($webhooks as $webhook) {
                try {
                    DeveloperWebhookDelivery::create([
                        'delivery_uid' => 'dlv_'.Str::lower((string) Str::ulid()),
                        'webhook_id' => $webhook->id,
                        'event_id' => $event->id,
                        'status' => DeveloperWebhookDelivery::PENDING,
                        'next_attempt_at' => now(),
                    ]);
                    $created++;
                } catch (QueryException $e) {
                    // Already exists for this (webhook, event): duplicate protection, not an error.
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }
                }
            }

            DeveloperEvent::query()->whereKey($event->id)->whereNull('fanned_out_at')->update(['fanned_out_at' => now()]);
        });

        return $created;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $code === '23000' || $code === '23505' || in_array($driverCode, [19, 1062], true);
    }
}
