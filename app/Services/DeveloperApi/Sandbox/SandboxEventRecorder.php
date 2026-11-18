<?php

namespace App\Services\DeveloperApi\Sandbox;

use App\Http\Resources\DeveloperApi\SandboxOrderResource;
use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Developer\SandboxOrder;
use App\Services\DeveloperApi\Webhooks\WebhookFanout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Persists SANDBOX webhook events through the same durable pipeline as production
 * (developer_events -> WebhookFanout -> developer_webhook_deliveries -> signed, SSRF-checked delivery),
 * tagged environment = 'sandbox'. That tag is what keeps the two worlds apart: fan-out only selects
 * endpoints of the same environment and the dispatcher re-checks it before every attempt.
 *
 * Events are recorded INSIDE the transaction that changes the sandbox order (record) so state and event
 * are atomic, and handed to delivery only AFTER it commits (publish). Neither step depends on the
 * production order sweeper. If publish fails the scheduled `developer-webhooks:run` fans out and delivers
 * the persisted event anyway.
 */
class SandboxEventRecorder
{
    public function __construct(private readonly WebhookFanout $fanout) {}

    /** @return ?DeveloperEvent null when this (order, type) was already recorded or the type is not a supported event */
    public function record(SandboxOrder $order, string $type): ?DeveloperEvent
    {
        if (! in_array($type, (array) config('developer_api.webhooks.events', []), true)) {
            return null;
        }

        try {
            return DeveloperEvent::create([
                'event_id' => 'evt_sbx_'.Str::lower((string) Str::ulid()),
                'application_id' => $order->application_id,
                'order_id' => $order->id, // a SANDBOX order id; (environment, order_id, type) is unique
                'type' => $type,
                'environment' => 'sandbox',
                // Never any secret, key, gateway credential or internal id.
                'payload' => [
                    'environment' => 'sandbox',
                    'simulated' => true,
                    'order' => (new SandboxOrderResource($order))->resolve(),
                ],
                'occurred_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fan out and queue delivery for freshly committed events. Best effort by design: the events are
     * already durable, and the scheduled pipeline picks up anything this misses.
     *
     * @param  list<DeveloperEvent>  $events
     */
    public function publish(array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->fanout->fanOut($event);

                DeveloperWebhookDelivery::query()
                    ->where('event_id', $event->id)
                    ->where('status', DeveloperWebhookDelivery::PENDING)
                    ->pluck('id')
                    ->each(fn ($id) => DeliverDeveloperWebhook::dispatch((int) $id));
            } catch (\Throwable $e) {
                Log::warning('Sandbox webhook publish failed; the scheduled pipeline will retry', ['event' => $event->event_id, 'error' => get_class($e)]);
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $code === '23000' || $code === '23505' || in_array($driverCode, [19, 1062], true);
    }
}
