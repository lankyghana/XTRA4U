<?php

namespace App\Services\DeveloperApi\Webhooks;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Services\DeveloperApi\DeveloperAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Portal-side management of webhook endpoints. Every URL is SSRF-validated here
 * (and again before each delivery). Signing secrets are returned in plaintext
 * exactly once — from create() and rotateSecret() — and are stored encrypted.
 */
class WebhookService
{
    public function __construct(
        private readonly WebhookUrlValidator $validator,
        private readonly DeveloperAuditLogger $audit,
    ) {}

    /** @return array{webhook: DeveloperWebhook, secret: string} */
    public function create(DeveloperApplication $app, string $actor, string $url, array $events, ?string $description): array
    {
        $max = (int) config('developer_api.webhooks.max_per_application', 5);
        if (DeveloperWebhook::query()->where('application_id', $app->id)->count() >= $max) {
            throw ValidationException::withMessages(['url' => "An application can have at most {$max} webhook endpoints."]);
        }

        $this->assertSafe($url);
        $events = $this->validEvents($events);
        $secret = WebhookSigner::generateSecret();

        $webhook = new DeveloperWebhook;
        $webhook->forceFill([
            'application_id' => $app->id,
            'url' => trim($url),
            'description' => $description,
            'events' => $events,
            'secret' => $secret,
            'secret_rotated_at' => now(),
            'is_active' => true,
        ])->save();

        $this->audit->log($actor, 'webhook.created', $app->id, ['webhook_id' => $webhook->id, 'host' => $webhook->displayHost()]);

        return ['webhook' => $webhook, 'secret' => $secret];
    }

    public function update(DeveloperWebhook $webhook, string $actor, array $data): DeveloperWebhook
    {
        if (isset($data['url']) && trim($data['url']) !== $webhook->url) {
            $this->assertSafe($data['url']);
            $webhook->url = trim($data['url']);
        }
        if (isset($data['events'])) {
            $webhook->events = $this->validEvents($data['events']);
        }
        if (array_key_exists('description', $data)) {
            $webhook->description = $data['description'];
        }
        $webhook->save();

        $this->audit->log($actor, 'webhook.updated', $webhook->application_id, ['webhook_id' => $webhook->id]);

        return $webhook;
    }

    public function setActive(DeveloperWebhook $webhook, string $actor, bool $active): void
    {
        // Re-enabling re-checks the destination: it may have changed since it was disabled.
        if ($active) {
            $this->assertSafe($webhook->url);
        }

        $webhook->forceFill(['is_active' => $active])->save();
        $this->audit->log($actor, $active ? 'webhook.enabled' : 'webhook.disabled', $webhook->application_id, ['webhook_id' => $webhook->id]);
    }

    /** @return string the NEW secret, shown once. The old secret stops being used immediately. */
    public function rotateSecret(DeveloperWebhook $webhook, string $actor): string
    {
        $secret = WebhookSigner::generateSecret();
        $webhook->forceFill(['secret' => $secret, 'secret_rotated_at' => now()])->save();
        $this->audit->log($actor, 'webhook.secret_rotated', $webhook->application_id, ['webhook_id' => $webhook->id]);

        return $secret;
    }

    public function delete(DeveloperWebhook $webhook, string $actor): void
    {
        DB::transaction(function () use ($webhook, $actor) {
            $webhook->forceFill(['is_active' => false])->save();
            DeveloperWebhookDelivery::query()
                ->where('webhook_id', $webhook->id)
                ->whereIn('status', [DeveloperWebhookDelivery::PENDING, DeveloperWebhookDelivery::DELIVERING])
                ->update(['status' => DeveloperWebhookDelivery::CANCELLED]);
            $webhook->delete(); // soft: delivery history is preserved
            $this->audit->log($actor, 'webhook.deleted', $webhook->application_id, ['webhook_id' => $webhook->id]);
        });
    }

    /** Only a FAILED delivery of an ACTIVE endpoint is retryable. */
    public function retry(DeveloperWebhook $webhook, DeveloperWebhookDelivery $delivery, string $actor): void
    {
        if ((int) $delivery->webhook_id !== (int) $webhook->id) {
            abort(404);
        }
        if (! $webhook->is_active || $delivery->status !== DeveloperWebhookDelivery::FAILED) {
            throw ValidationException::withMessages(['delivery' => 'Only failed deliveries of an active endpoint can be retried.']);
        }

        $updated = DeveloperWebhookDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeveloperWebhookDelivery::FAILED)
            ->update([
                'status' => DeveloperWebhookDelivery::PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'manual_retries' => DB::raw('manual_retries + 1'),
            ]);

        if ($updated === 1) {
            $this->audit->log($actor, 'webhook.delivery_retried', $webhook->application_id, ['delivery_id' => $delivery->id]);
            DeliverDeveloperWebhook::dispatch($delivery->id);
        }
    }

    private function assertSafe(string $url): void
    {
        try {
            $this->validator->validate($url);
        } catch (UnsafeWebhookDestination $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }

    /** @return list<string> */
    private function validEvents(array $events): array
    {
        $allowed = (array) config('developer_api.webhooks.events', []);
        $events = array_values(array_unique(array_map('strval', $events)));

        if ($events === [] || array_diff($events, $allowed) !== []) {
            throw ValidationException::withMessages(['events' => 'Select at least one supported event type.']);
        }

        return $events;
    }
}
