<?php

namespace Tests\Feature\DeveloperApi;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class WebhookManagementTest extends PortalTestCase
{
    private function store($dev, $app, array $o = [])
    {
        return $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks", array_merge([
            'url' => 'https://hooks.example.com/in', 'description' => 'prod hook', 'events' => ['order.created', 'payment.succeeded'],
        ], $o));
    }

    private function secretFrom($response): string
    {
        $this->assertSame(1, preg_match('/whsec_[A-Za-z0-9]{40}/', $response->getContent(), $m));

        return $m[0];
    }

    private function delivery(DeveloperWebhook $hook, string $status): DeveloperWebhookDelivery
    {
        $event = DeveloperEvent::create([
            'event_id' => 'evt_'.uniqid(), 'application_id' => $hook->application_id, 'order_id' => random_int(1, 99999),
            'type' => 'order.created', 'environment' => 'production', 'payload' => ['order' => ['id' => 'ord_x']],
            'occurred_at' => now(), 'fanned_out_at' => now(),
        ]);

        return DeveloperWebhookDelivery::create([
            'delivery_uid' => 'dlv_'.uniqid(), 'webhook_id' => $hook->id, 'event_id' => $event->id, 'status' => $status,
            'attempts' => 6, 'total_attempts' => 6, 'last_status_code' => 500, 'last_error' => 'http_error',
        ]);
    }

    // ── create ──────────────────────────────────────────────────────────────

    public function test_registering_an_endpoint_shows_the_secret_once_and_stores_it_encrypted(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $res = $this->store($dev, $app)->assertOk();
        $secret = $this->secretFrom($res);

        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));

        $hook = DeveloperWebhook::firstOrFail();
        $raw = DB::table('developer_webhooks')->where('id', $hook->id)->value('secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);          // ciphertext at rest
        $this->assertSame($secret, $hook->secret);                     // decrypts for signing
        $this->assertArrayNotHasKey('secret', $hook->toArray());       // never serialised

        $this->get("/developers/applications/{$app->id}/webhooks")->assertOk()->assertDontSee($secret);
        $this->get("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries")->assertOk()->assertDontSee($secret);
        $this->get('/developers/dashboard')->assertOk()->assertDontSee($secret);
    }

    public function test_the_secret_page_carries_a_verification_example(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->store($dev, $app)->assertSee('X-XTRA4U-Signature')->assertSee('HMAC-SHA256');
    }

    public function test_events_must_be_real_supported_states(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->store($dev, $app, ['events' => []])->assertSessionHasErrors('events');
        $this->store($dev, $app, ['events' => ['order.refunded']])->assertSessionHasErrors('events.0');
        $this->store($dev, $app, ['events' => ['airtime.sent']])->assertSessionHasErrors('events.0');
        $this->store($dev, $app, ['events' => ['order.created', 'afa.registered']])->assertSessionHasErrors('events.1');
        $this->assertSame(0, DeveloperWebhook::count());

        $this->assertEqualsCanonicalizing(
            ['order.created', 'order.processing', 'order.completed', 'order.failed', 'payment.pending', 'payment.succeeded', 'payment.failed'],
            config('developer_api.webhooks.events')
        );
    }

    public function test_duplicate_event_selections_are_collapsed(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->store($dev, $app, ['events' => ['order.created', 'order.created', 'payment.failed']])->assertOk();

        $this->assertSame(['order.created', 'payment.failed'], DeveloperWebhook::firstOrFail()->events);
    }

    public function test_the_per_application_endpoint_cap_is_enforced(): void
    {
        config(['developer_api.webhooks.max_per_application' => 2]);
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->store($dev, $app)->assertOk();
        $this->store($dev, $app)->assertOk();
        $this->store($dev, $app)->assertSessionHasErrors('url');
        $this->assertSame(2, DeveloperWebhook::count());
    }

    public function test_privileged_fields_cannot_be_mass_assigned_through_the_form(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $other = $this->sandboxApp($this->developer());

        $this->store($dev, $app, ['application_id' => $other->id, 'secret' => 'whsec_chosenbyattacker', 'is_active' => false, 'consecutive_failures' => 99])->assertOk();

        $hook = DeveloperWebhook::firstOrFail();
        $this->assertSame($app->id, (int) $hook->application_id);
        $this->assertNotSame('whsec_chosenbyattacker', $hook->secret);
        $this->assertTrue($hook->is_active);
        $this->assertSame(0, (int) $hook->consecutive_failures);
    }

    // ── update / toggle / rotate / delete ───────────────────────────────────

    public function test_update_changes_url_events_and_description_but_never_the_secret(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook, $secret] = $this->makeWebhook($app);
        $this->dns->set('other.example.org', '8.8.8.8');

        $this->actingAsDeveloper($dev)->put("/developers/applications/{$app->id}/webhooks/{$hook->id}", [
            'url' => 'https://other.example.org/new', 'description' => 'moved', 'events' => ['order.failed'], 'secret' => 'whsec_x',
        ])->assertRedirect();

        $hook->refresh();
        $this->assertSame('https://other.example.org/new', $hook->url);
        $this->assertSame(['order.failed'], $hook->events);
        $this->assertSame('moved', $hook->description);
        $this->assertSame($secret, $hook->secret);
    }

    public function test_toggle_disables_and_re_enables(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/toggle")->assertRedirect();
        $this->assertFalse($hook->fresh()->is_active);
        $this->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/toggle")->assertRedirect();
        $this->assertTrue($hook->fresh()->is_active);
    }

    public function test_rotating_the_secret_shows_the_new_one_once_and_discards_the_old(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook, $old] = $this->makeWebhook($app);

        $res = $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/rotate-secret")->assertOk();
        $new = $this->secretFrom($res);

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $hook->fresh()->secret);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $res->assertDontSee($old);
        $this->get("/developers/applications/{$app->id}/webhooks")->assertDontSee($new)->assertDontSee($old);
    }

    public function test_deleting_soft_deletes_cancels_open_deliveries_and_keeps_history(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);
        $pending = $this->delivery($hook, 'pending');
        $failed = $this->delivery($hook, 'failed');
        $done = $this->delivery($hook, 'succeeded');

        $this->actingAsDeveloper($dev)->delete("/developers/applications/{$app->id}/webhooks/{$hook->id}")->assertRedirect();

        $this->assertSoftDeleted($hook);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('succeeded', $done->fresh()->status);
        $this->get("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries")->assertNotFound();
    }

    // ── delivery history & retry ────────────────────────────────────────────

    public function test_delivery_history_shows_status_and_response_codes(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);
        $this->delivery($hook, 'failed');

        $this->actingAsDeveloper($dev)->get("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries")
            ->assertOk()->assertSee('failed')->assertSee('HTTP 500')->assertSee('order.created');
    }

    public function test_a_failed_delivery_can_be_retried_once_queued_as_pending(): void
    {
        Queue::fake();
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);
        $d = $this->delivery($hook, 'failed');

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries/{$d->id}/retry")->assertRedirect();

        $d->refresh();
        $this->assertSame('pending', $d->status);
        $this->assertSame(0, (int) $d->attempts);
        $this->assertSame(1, (int) $d->manual_retries);
        $this->assertSame(6, (int) $d->total_attempts); // attempt history is never reset
        Queue::assertPushed(DeliverDeveloperWebhook::class, 1);

        // Already pending: a second click cannot double-queue it.
        $this->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries/{$d->id}/retry")->assertSessionHasErrors('delivery');
        Queue::assertPushed(DeliverDeveloperWebhook::class, 1);
    }

    public function test_only_failed_deliveries_of_active_endpoints_are_retryable(): void
    {
        Queue::fake();
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);

        foreach (['succeeded', 'pending', 'delivering', 'cancelled'] as $status) {
            $d = $this->delivery($hook, $status);
            $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries/{$d->id}/retry")->assertSessionHasErrors('delivery');
            $this->assertSame($status, $d->fresh()->status);
        }

        $failed = $this->delivery($hook, 'failed');
        $hook->forceFill(['is_active' => false])->save();
        $this->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries/{$failed->id}/retry")->assertSessionHasErrors('delivery');
        $this->assertSame('failed', $failed->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_delivery_of_another_webhook_cannot_be_retried_through_this_one(): void
    {
        Queue::fake();
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$a] = $this->makeWebhook($app);
        [$b] = $this->makeWebhook($app);
        $d = $this->delivery($b, 'failed');

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks/{$a->id}/deliveries/{$d->id}/retry")->assertNotFound();
        $this->assertSame('failed', $d->fresh()->status);
    }

    // ── isolation ───────────────────────────────────────────────────────────

    public function test_another_developer_cannot_read_retry_rotate_or_delete(): void
    {
        Queue::fake();
        $owner = $this->developer();
        $intruder = $this->developer();
        $app = $this->sandboxApp($owner);
        [$hook, $secret] = $this->makeWebhook($app);
        $d = $this->delivery($hook, 'failed');

        $this->actingAsDeveloper($intruder);
        $base = "/developers/applications/{$app->id}/webhooks/{$hook->id}";
        $this->get($base.'/deliveries')->assertNotFound();
        $this->post($base.'/deliveries/'.$d->id.'/retry')->assertNotFound();
        $this->post($base.'/rotate-secret')->assertNotFound();
        $this->post($base.'/toggle')->assertNotFound();
        $this->put($base, ['url' => 'https://hooks.example.com/x', 'events' => ['order.created']])->assertNotFound();
        $this->delete($base)->assertNotFound();

        $hook->refresh();
        $this->assertNull($hook->deleted_at);
        $this->assertSame($secret, $hook->secret);
        $this->assertSame('failed', $d->fresh()->status);
    }

    public function test_the_webhook_of_a_sibling_application_is_not_addressable_through_another_application(): void
    {
        $dev = $this->developer();
        $a = $this->sandboxApp($dev);
        $b = $this->sandboxApp($dev);
        [$hookOfB] = $this->makeWebhook($b);

        $this->actingAsDeveloper($dev)->get("/developers/applications/{$a->id}/webhooks/{$hookOfB->id}/deliveries")->assertNotFound();
        $this->post("/developers/applications/{$a->id}/webhooks/{$hookOfB->id}/rotate-secret")->assertNotFound();
        $this->delete("/developers/applications/{$a->id}/webhooks/{$hookOfB->id}")->assertNotFound();
    }

    public function test_webhook_actions_are_audited_without_secrets(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $secret = $this->secretFrom($this->store($dev, $app));

        $rows = \App\Models\Developer\DeveloperAuditEvent::all();
        $this->assertTrue($rows->contains('action', 'webhook.created'));
        $this->assertStringNotContainsString($secret, json_encode($rows->toArray()));
    }
}
