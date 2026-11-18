<?php

namespace Tests\Feature\DeveloperApi;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookAttempt;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Developer\SandboxOrder;
use App\Models\Order;
use App\Services\DeveloperApi\Sandbox\SandboxEventRecorder;
use App\Services\DeveloperApi\Webhooks\OrderEventDetector;
use App\Services\DeveloperApi\Webhooks\TransportResult;
use App\Services\DeveloperApi\Webhooks\ValidatedDestination;
use App\Services\DeveloperApi\Webhooks\WebhookDispatcher;
use App\Services\DeveloperApi\Webhooks\WebhookFanout;
use App\Services\DeveloperApi\Webhooks\WebhookService;
use App\Services\DeveloperApi\Webhooks\WebhookSigner;
use App\Services\DeveloperApi\Webhooks\WebhookTransport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class SandboxWebhookTest extends SandboxTestCase
{
    private const ALL = ['order.created', 'order.processing', 'order.completed', 'order.failed', 'payment.pending', 'payment.succeeded', 'payment.failed'];

    /** @return array{0:DeveloperWebhook,1:string} */
    private function hook(DeveloperApplication $app, string $env, string $url, array $events = self::ALL): array
    {
        $r = app(WebhookService::class)->create($app, 'test', $url, $events, null, $env);

        return [$r['webhook'], $r['secret']];
    }

    /** Runs the whole happy path for one sandbox order. @return string sandbox order id */
    private function fullFlow(array $w, string $scenario = 'success', string $idem = 'k1'): string
    {
        $id = $this->sandboxOrderId($w['key'], $w['offer'], $idem);
        $this->startPayment($w['key'], $id)->assertStatus(202);
        $this->simulate($w['key'], $id, ['scenario' => $scenario])->assertOk();

        return $id;
    }

    private function sandboxEventTypes(): array
    {
        return DeveloperEvent::where('environment', 'sandbox')->orderBy('id')->pluck('type')->all();
    }

    // ── creation & persistence ───────────────────────────────────────────────

    public function test_sandbox_events_are_persisted_at_each_real_transition_without_any_sweeper(): void
    {
        $w = $this->sandboxWorld();

        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->assertSame(['order.created'], $this->sandboxEventTypes());

        $this->startPayment($w['key'], $id);
        $this->assertSame(['order.created', 'payment.pending'], $this->sandboxEventTypes());

        $this->simulate($w['key'], $id, ['scenario' => 'success']);
        $this->assertSame(
            ['order.created', 'payment.pending', 'payment.succeeded', 'order.processing', 'order.completed'],
            $this->sandboxEventTypes()
        );

        // All of that happened with no webhook endpoint, no scheduler and no order sweeper.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DeveloperWebhookDelivery::count());
    }

    public function test_a_failed_payment_emits_payment_failed_and_order_failed(): void
    {
        $w = $this->sandboxWorld();
        $this->fullFlow($w, 'payment_failed');

        $this->assertSame(['order.created', 'payment.pending', 'payment.failed', 'order.failed'], $this->sandboxEventTypes());
    }

    public function test_a_failed_fulfillment_emits_order_failed(): void
    {
        $w = $this->sandboxWorld();
        $this->fullFlow($w, 'fulfillment_failed');

        $this->assertSame(
            ['order.created', 'payment.pending', 'payment.succeeded', 'order.processing', 'order.failed'],
            $this->sandboxEventTypes()
        );
    }

    public function test_no_event_is_invented_without_a_real_transition(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->fullFlow($w);
        $count = DeveloperEvent::count();

        // Replays and reads change nothing.
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk();
        $this->simulate($w['key'], $id, ['event' => 'fulfillment.completed'])->assertOk();
        $this->getJson("/api/v1/orders/{$id}", $this->auth($w['key']))->assertOk();
        $this->getJson("/api/v1/orders/{$id}/status", $this->auth($w['key']))->assertOk();

        $this->assertSame($count, DeveloperEvent::count());
        $this->assertSame([], array_diff($this->sandboxEventTypes(), self::ALL), 'only supported event types exist');
    }

    // ── payload & signing ────────────────────────────────────────────────────

    public function test_the_delivered_payload_is_signed_identifies_the_sandbox_and_leaks_nothing(): void
    {
        $w = $this->sandboxWorld();
        [$hook, $secret] = $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in', ['order.completed']);

        $id = $this->fullFlow($w);

        $this->assertCount(1, $this->transport->sent);
        $sent = $this->transport->sent[0];
        $body = json_decode($sent['body'], true);

        $this->assertSame('order.completed', $body['type']);
        $this->assertSame('sandbox', $body['environment']);
        $this->assertTrue($body['simulated']);
        $this->assertSame('sandbox', $body['data']['environment']);
        $this->assertSame($id, $body['data']['order']['id']);
        $this->assertSame('completed', $body['data']['order']['status']);
        $this->assertMatchesRegularExpression('/^evt_sbx_[a-z0-9]{26}$/', $body['id']);
        $this->assertSame('v1', $body['api_version']);

        $this->assertSame('sandbox', $sent['headers']['X-XTRA4U-Environment']);
        $this->assertSame('order.completed', $sent['headers']['X-XTRA4U-Event-Type']);
        $this->assertSame($body['id'], $sent['headers']['X-XTRA4U-Event-Id']);
        $this->assertTrue(WebhookSigner::verify($sent['headers'][WebhookSigner::HEADER], $sent['body'], $secret), 'valid HMAC-SHA256 signature');
        $this->assertFalse(WebhookSigner::verify($sent['headers'][WebhookSigner::HEADER], $sent['body'], WebhookSigner::generateSecret()));
        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $sent['headers'][WebhookSigner::HEADER]);

        // No secret, key or credential anywhere in the payload.
        $this->assertStringNotContainsString($secret, $sent['body']);
        $this->assertStringNotContainsString('xt_test_', $sent['body']);
        $this->assertStringNotContainsString($w['key'], $sent['body']);
        $this->assertStringNotContainsString('whsec_', $sent['body']);
        $this->assertStringNotContainsString('vendor_id', $sent['body']);
        $this->assertStringNotContainsString('application_id', $sent['body']);
    }

    public function test_the_event_body_is_byte_identical_on_every_retry(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in', ['order.created']);
        $this->transport->outcome = 'http_error';
        $this->transport->status = 500;

        $this->sandboxOrderId($w['key'], $w['offer']);
        $delivery = DeveloperWebhookDelivery::firstOrFail();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->total_attempts);
        $this->assertTrue($delivery->next_attempt_at->isFuture(), 'exponential back-off scheduled');

        DeveloperWebhookDelivery::whereKey($delivery->id)->update(['next_attempt_at' => now()->subSecond()]);
        app(WebhookDispatcher::class)->dispatchDue();

        $this->assertCount(2, $this->transport->sent);
        $this->assertSame($this->transport->sent[0]['body'], $this->transport->sent[1]['body']);
        $this->assertSame('1', $this->transport->sent[0]['headers']['X-XTRA4U-Delivery-Attempt']);
        $this->assertSame('2', $this->transport->sent[1]['headers']['X-XTRA4U-Delivery-Attempt']);
        $this->assertSame(2, DeveloperWebhookAttempt::where('delivery_id', $delivery->id)->count());
    }

    // ── duplicate protection ─────────────────────────────────────────────────

    public function test_duplicate_events_and_deliveries_cannot_exist(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        $id = $this->fullFlow($w);

        $events = DeveloperEvent::count();
        $deliveries = DeveloperWebhookDelivery::count();
        $order = SandboxOrder::firstOrFail();

        // Recording the same (order, type) again yields nothing...
        $this->assertNull(app(SandboxEventRecorder::class)->record($order, 'order.completed'));
        // ...and re-running fan-out over everything creates no new delivery.
        DeveloperEvent::query()->update(['fanned_out_at' => null]);
        $this->assertSame(0, app(WebhookFanout::class)->run());

        $this->assertSame($events, DeveloperEvent::count());
        $this->assertSame($deliveries, DeveloperWebhookDelivery::count());
        $this->assertCount($deliveries, $this->transport->sent, 'each event was delivered exactly once');
        $this->assertNotEmpty($id);
    }

    public function test_the_scheduled_pipeline_does_not_duplicate_or_disturb_sandbox_events(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        $this->fullFlow($w);
        $events = DeveloperEvent::count();
        $sent = count($this->transport->sent);

        $this->artisan('developer-webhooks:run')->assertSuccessful();

        $this->assertSame($events, DeveloperEvent::count());
        $this->assertSame($sent, count($this->transport->sent));
        $this->assertSame(0, app(OrderEventDetector::class)->sweep(), 'the production sweeper never sees sandbox orders');
    }

    public function test_sandbox_and_production_ids_may_overlap_without_colliding(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        // Both orders emit order.created.
        $this->sandboxOrderId($w['key'], $w['offer']);
        $this->order($live, $this->quote($live, $w['offer'])->json('data.reference'), 'p1')->assertCreated();
        app(OrderEventDetector::class)->sweep();

        // Force the numbers to overlap rather than relying on auto-increment behaviour (which differs per
        // engine: SQLite reuses ids after a rollback, MySQL/MariaDB do not).
        $sid = SandboxOrder::firstOrFail()->id;
        if (! DeveloperEvent::where('environment', 'production')->where('order_id', $sid)->where('type', 'order.created')->exists()) {
            DeveloperEvent::create([
                'event_id' => 'evt_prod_overlap', 'application_id' => $w['app']->id, 'order_id' => $sid, 'type' => 'order.created',
                'environment' => 'production', 'payload' => [], 'occurred_at' => now(),
            ]);
        }

        $this->assertSame(1, DeveloperEvent::where('environment', 'sandbox')->where('order_id', $sid)->where('type', 'order.created')->count());
        $this->assertSame(1, DeveloperEvent::where('environment', 'production')->where('order_id', $sid)->where('type', 'order.created')->count());

        // The unique key still stops a duplicate WITHIN an environment.
        $this->expectException(QueryException::class);
        DeveloperEvent::create([
            'event_id' => 'evt_dup', 'application_id' => $w['app']->id, 'order_id' => $sid, 'type' => 'order.created',
            'environment' => 'sandbox', 'payload' => [], 'occurred_at' => now(),
        ]);
    }

    // ── environment separation of endpoints ──────────────────────────────────

    public function test_sandbox_events_only_reach_sandbox_endpoints(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        $this->hook($w['app'], 'production', 'https://other.example.org/live');

        $this->fullFlow($w);

        $this->assertNotEmpty($this->transport->sent);
        $this->assertSame(['hooks.example.com'], array_values(array_unique(array_column($this->transport->sent, 'host'))));
        $this->assertSame(0, DeveloperWebhookDelivery::query()->whereIn('webhook_id', DeveloperWebhook::where('environment', 'production')->select('id'))->count());
    }

    public function test_production_events_only_reach_production_endpoints(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        $this->hook($w['app'], 'production', 'https://other.example.org/live');

        $this->order($live, $this->quote($live, $w['offer'])->json('data.reference'), 'p1')->assertCreated();
        app(OrderEventDetector::class)->sweep();
        app(WebhookFanout::class)->run();
        app(WebhookDispatcher::class)->dispatchDue();

        $this->assertNotEmpty($this->transport->sent);
        $this->assertSame(['other.example.org'], array_values(array_unique(array_column($this->transport->sent, 'host'))));
        foreach ($this->transport->sent as $s) {
            $this->assertArrayNotHasKey('X-XTRA4U-Environment', $s['headers']);                    // production wire format unchanged
            $this->assertArrayNotHasKey('environment', json_decode($s['body'], true));
        }
        $this->assertSame(0, DeveloperWebhookDelivery::query()->whereIn('webhook_id', DeveloperWebhook::where('environment', 'sandbox')->select('id'))->count());
    }

    public function test_events_never_cross_applications(): void
    {
        $a = $this->sandboxWorld();
        $b = $this->sandboxWorld();
        $this->hook($b['app'], 'sandbox', 'https://other.example.org/b');

        $this->fullFlow($a);

        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, DeveloperWebhookDelivery::count());
    }

    public function test_the_dispatcher_refuses_a_cross_environment_delivery_even_if_one_were_created_wrongly(): void
    {
        $w = $this->sandboxWorld();
        [$prodHook] = $this->hook($w['app'], 'production', 'https://other.example.org/live');
        [$sbxHook] = $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        $this->sandboxOrderId($w['key'], $w['offer']);                   // a sandbox order.created event
        $sandboxEvent = DeveloperEvent::where('environment', 'sandbox')->firstOrFail();
        $this->transport->sent = [];

        // Force the impossible: a sandbox event queued for a PRODUCTION endpoint.
        $bad = DeveloperWebhookDelivery::create([
            'delivery_uid' => 'dlv_bad', 'webhook_id' => $prodHook->id, 'event_id' => $sandboxEvent->id,
            'status' => 'pending', 'next_attempt_at' => now()->subSecond(),
        ]);
        app(WebhookDispatcher::class)->deliver($bad->id);

        $this->assertSame('cancelled', $bad->fresh()->status);
        $this->assertSame('environment_mismatch', $bad->fresh()->last_error);
        $this->assertSame([], $this->transport->sent, 'nothing was sent to the production endpoint');

        // ...and the reverse: a production event for a sandbox endpoint.
        $prodEvent = DeveloperEvent::create([
            'event_id' => 'evt_prod_x', 'application_id' => $w['app']->id, 'order_id' => 999, 'type' => 'order.created',
            'environment' => 'production', 'payload' => ['order' => []], 'occurred_at' => now(),
        ]);
        $bad2 = DeveloperWebhookDelivery::create([
            'delivery_uid' => 'dlv_bad2', 'webhook_id' => $sbxHook->id, 'event_id' => $prodEvent->id,
            'status' => 'pending', 'next_attempt_at' => now()->subSecond(),
        ]);
        app(WebhookDispatcher::class)->deliver($bad2->id);

        $this->assertSame('cancelled', $bad2->fresh()->status);
        $this->assertSame([], $this->transport->sent);
    }

    // ── SSRF protection applies to sandbox endpoints too ─────────────────────

    public function test_sandbox_webhook_urls_get_the_same_ssrf_validation(): void
    {
        $w = $this->sandboxWorld();
        $this->dns->set('internal.example.com', '10.0.0.5')->set('meta.example.com', '169.254.169.254');

        foreach (['http://hooks.example.com/in', 'https://127.0.0.1/in', 'https://localhost/in', 'https://internal.example.com/in', 'https://meta.example.com/in', 'https://hooks.example.com:8443/in', 'https://user:pw@hooks.example.com/in'] as $url) {
            try {
                $this->hook($w['app'], 'sandbox', $url);
                $this->fail("A sandbox endpoint was accepted for {$url}");
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, DeveloperWebhook::count());
    }

    public function test_a_sandbox_delivery_is_blocked_if_dns_later_points_at_a_private_address(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in', ['order.created']);

        $this->dns->set('hooks.example.com', '10.1.2.3');     // rebinding after registration
        $this->sandboxOrderId($w['key'], $w['offer']);

        $this->assertSame([], $this->transport->sent, 'no request may be made to a private address');
        $attempt = DeveloperWebhookAttempt::firstOrFail();
        $this->assertSame('blocked', $attempt->outcome);
    }

    // ── endpoint management ──────────────────────────────────────────────────

    public function test_an_endpoint_belongs_to_one_environment_chosen_at_creation(): void
    {
        $w = $this->sandboxWorld();
        [$sbx] = $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        [$prod] = $this->hook($w['app'], 'production', 'https://other.example.org/live');

        $this->assertSame('sandbox', $sbx->fresh()->environment);
        $this->assertSame('production', $prod->fresh()->environment);

        // Defaults to production (existing behaviour) and rejects anything else.
        $default = app(WebhookService::class)->create($w['app'], 'test', 'https://hooks.example.com/d', ['order.created'], null)['webhook'];
        $this->assertSame('production', $default->environment);
        $this->expectException(ValidationException::class);
        app(WebhookService::class)->create($w['app'], 'test', 'https://hooks.example.com/x', ['order.created'], null, 'staging');
    }

    public function test_the_endpoint_limit_is_counted_per_environment(): void
    {
        config(['developer_api.webhooks.max_per_application' => 2]);
        $w = $this->sandboxWorld();

        $this->hook($w['app'], 'production', 'https://hooks.example.com/1');
        $this->hook($w['app'], 'production', 'https://hooks.example.com/2');
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/3');     // not blocked by the production endpoints
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/4');

        $this->expectException(ValidationException::class);
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/5');
    }

    public function test_existing_endpoints_default_to_production(): void
    {
        $w = $this->sandboxWorld();
        $row = new DeveloperWebhook;
        $row->forceFill(['application_id' => $w['app']->id, 'url' => 'https://hooks.example.com/old', 'events' => ['order.created'], 'secret' => 'x', 'is_active' => true])->save();

        $this->assertSame('production', $row->fresh()->environment);
    }

    // ── resilience ───────────────────────────────────────────────────────────

    public function test_a_crashing_webhook_transport_never_breaks_the_sandbox_api(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in');
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(ValidatedDestination $destination, array $headers, string $body): TransportResult
            {
                throw new \RuntimeException('network exploded');
            }
        });

        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id)->assertStatus(202);
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk()->assertJsonPath('data.order.status', 'completed');

        // The events are durable and the deliveries are recoverable by the scheduled pipeline.
        $this->assertSame(5, DeveloperEvent::where('environment', 'sandbox')->count());
        $this->assertGreaterThan(0, DeveloperWebhookDelivery::count());
    }

    public function test_events_are_queued_for_delivery_after_commit_not_sent_inline_when_a_queue_is_used(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox', 'https://hooks.example.com/in', ['order.created']);
        Queue::fake();

        $this->sandboxOrderId($w['key'], $w['offer']);

        Queue::assertPushed(DeliverDeveloperWebhook::class, 1);
        $this->assertSame([], $this->transport->sent);
    }
}
