<?php

namespace App\Services\DeveloperApi\Webhooks;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookAttempt;
use App\Models\Developer\DeveloperWebhookDelivery;

/**
 * Performs ONE delivery attempt for a delivery row, then schedules the retry or
 * closes it. Safe to call concurrently or repeatedly: only the caller that wins
 * the conditional claim sends anything.
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookUrlValidator $validator,
        private readonly WebhookTransport $transport,
    ) {}

    /** Queue every delivery that is due (including ones orphaned by a dead worker). @return int queued */
    public function dispatchDue(): int
    {
        $stale = now()->subSeconds((int) config('developer_api.webhooks.claim_timeout_seconds', 300));

        $ids = DeveloperWebhookDelivery::query()
            ->where(function ($q) use ($stale) {
                $q->where(fn ($q) => $q->where('status', DeveloperWebhookDelivery::PENDING)->where('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('status', DeveloperWebhookDelivery::DELIVERING)->where('claimed_at', '<', $stale));
            })
            ->orderBy('id')
            ->limit((int) config('developer_api.webhooks.sweep_batch', 200))
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverDeveloperWebhook::dispatch((int) $id);
        }

        return $ids->count();
    }

    public function deliver(int $deliveryId): void
    {
        if (! $this->claim($deliveryId)) {
            return; // not due, already succeeded/failed, or another worker has it
        }

        $delivery = DeveloperWebhookDelivery::query()->with(['event', 'webhook.application.vendor'])->findOrFail($deliveryId);
        $webhook = $delivery->webhook;

        // A deleted or disabled endpoint never receives anything, and neither does one whose owning
        // vendor is no longer approved (read live: suspension stops delivery before any cleanup runs).
        if (! $webhook || $webhook->trashed() || ! $webhook->is_active || ! $webhook->application?->isUsable()) {
            $delivery->forceFill(['status' => DeveloperWebhookDelivery::CANCELLED, 'claimed_at' => null])->save();

            return;
        }

        // Defence in depth: fan-out already matches environments, but a sandbox event must NEVER be sent to a
        // production endpoint (or the reverse) even if a delivery row were ever created wrongly.
        if ($webhook->environment !== $delivery->event->environment) {
            $delivery->forceFill(['status' => DeveloperWebhookDelivery::CANCELLED, 'claimed_at' => null, 'last_error' => 'environment_mismatch'])->save();

            return;
        }

        $body = $this->body($delivery);
        $attemptNo = $delivery->total_attempts + 1;

        try {
            // Re-validated on EVERY attempt: DNS may have changed since registration (rebinding).
            $destination = $this->validator->validate($webhook->url);

            $timestamp = time();
            $result = $this->transport->send($destination, [
                'Content-Type' => 'application/json',
                'User-Agent' => 'XTRA4U-Webhooks/1.0',
                'X-XTRA4U-Event-Id' => $delivery->event->event_id,
                'X-XTRA4U-Event-Type' => $delivery->event->type,
                'X-XTRA4U-Delivery-Id' => $delivery->delivery_uid,
                'X-XTRA4U-Delivery-Attempt' => (string) $attemptNo,
            ] + ($delivery->event->environment === 'sandbox' ? ['X-XTRA4U-Environment' => 'sandbox'] : []) + [
                WebhookSigner::HEADER => WebhookSigner::header($webhook->secret, $timestamp, $body),
            ], $body);
        } catch (UnsafeWebhookDestination $e) {
            // Fail closed: no request was made.
            $result = new TransportResult('blocked', null, 0, $e->reason);
        }

        $this->settle($delivery, $webhook, $attemptNo, $result);
    }

    private function claim(int $id): bool
    {
        $stale = now()->subSeconds((int) config('developer_api.webhooks.claim_timeout_seconds', 300));

        return DeveloperWebhookDelivery::query()
            ->whereKey($id)
            ->where(function ($q) use ($stale) {
                $q->where(fn ($q) => $q->where('status', DeveloperWebhookDelivery::PENDING)->where('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('status', DeveloperWebhookDelivery::DELIVERING)->where('claimed_at', '<', $stale));
            })
            ->update(['status' => DeveloperWebhookDelivery::DELIVERING, 'claimed_at' => now()]) === 1;
    }

    private function settle(DeveloperWebhookDelivery $delivery, DeveloperWebhook $webhook, int $attemptNo, TransportResult $result): void
    {
        DeveloperWebhookAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_no' => $attemptNo,
            'outcome' => $result->outcome,
            'status_code' => $result->statusCode,
            'error' => $result->error,
            'duration_ms' => $result->durationMs,
        ]);

        $backoff = array_values((array) config('developer_api.webhooks.backoff_seconds', [60, 300, 1800, 7200, 21600]));
        $maxAttempts = count($backoff) + 1;
        $attempts = $delivery->attempts + 1;

        $update = [
            'attempts' => $attempts,
            'total_attempts' => $attemptNo,
            'last_status_code' => $result->statusCode,
            'last_error' => $result->succeeded() ? null : $result->error,
            'claimed_at' => null,
        ];

        if ($result->succeeded()) {
            $update += ['status' => DeveloperWebhookDelivery::SUCCEEDED, 'delivered_at' => now(), 'next_attempt_at' => null];
            $webhook->forceFill(['consecutive_failures' => 0, 'last_delivery_at' => now()])->save();
        } elseif ($attempts >= $maxAttempts) {
            $update += ['status' => DeveloperWebhookDelivery::FAILED, 'next_attempt_at' => null];
            $webhook->forceFill(['consecutive_failures' => $webhook->consecutive_failures + 1, 'last_delivery_at' => now()])->save();
        } else {
            $update += ['status' => DeveloperWebhookDelivery::PENDING, 'next_attempt_at' => now()->addSeconds((int) $backoff[$attempts - 1])];
            $webhook->forceFill(['consecutive_failures' => $webhook->consecutive_failures + 1, 'last_delivery_at' => now()])->save();
        }

        $delivery->forceFill($update)->save();
    }

    /** The exact bytes that are signed and sent. Identical on every retry of the same event. */
    public function body(DeveloperWebhookDelivery $delivery): string
    {
        $event = $delivery->event;

        // Production bodies are unchanged byte-for-byte. Sandbox events additionally declare themselves.
        $sandbox = $event->environment === 'sandbox' ? ['environment' => 'sandbox', 'simulated' => true] : [];

        return json_encode([
            'id' => $event->event_id,
            'type' => $event->type,
            'api_version' => config('developer_api.version'),
            'created' => $event->occurred_at->getTimestamp(),
        ] + $sandbox + [
            'data' => $event->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
