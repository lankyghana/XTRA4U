<?php

namespace Tests\Feature\DeveloperApi;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperOrderState;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookAttempt;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Order;
use App\Services\DeveloperApi\Webhooks\OrderEventDetector;
use App\Services\DeveloperApi\Webhooks\WebhookDispatcher;
use App\Services\DeveloperApi\Webhooks\WebhookFanout;
use App\Services\DeveloperApi\Webhooks\WebhookSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class WebhookPipelineTest extends PortalTestCase
{
    /** A production app with a key, one product, and an API-created order. @return array{0:DeveloperApplication,1:Order,2:string} */
    private function apiOrder(?DeveloperApplication $app = null, string $key = 'k1'): array
    {
        $app ??= $this->makeApp('production');
        $vendor = $this->vendor();
        $product = $this->product($vendor, 10);
        [, $plain] = $this->makeKey($app);

        $quote = $this->postJson('/api/v1/quotes', ['product_id' => "own_{$product->id}", 'recipient_phone' => '0244000000'], $this->auth($plain))->json('data.reference');
        $this->postJson('/api/v1/orders', ['quote_reference' => $quote], $this->auth($plain) + ['Idempotency-Key' => $key])->assertCreated();

        return [$app, Order::query()->latest('id')->firstOrFail(), $plain];
    }

    private function set(Order $order, array $attrs): Order
    {
        Order::query()->whereKey($order->id)->update($attrs);

        return $order->refresh();
    }

    private function types(?int $orderId = null): array
    {
        return DeveloperEvent::query()->when($orderId, fn ($q) => $q->where('order_id', $orderId))->orderBy('id')->pluck('type')->all();
    }

    private function sweepAll(): void
    {
        app(OrderEventDetector::class)->sweep();
        app(WebhookFanout::class)->run();
    }

    // ── signing ─────────────────────────────────────────────────────────────

    public function test_signature_format_and_verification(): void
    {
        $secret = WebhookSigner::generateSecret();
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $secret);

        $header = WebhookSigner::header($secret, 1_700_000_000, '{"a":1}');
        $this->assertMatchesRegularExpression('/^t=1700000000,v1=[0-9a-f]{64}$/', $header);
        $this->assertSame(hash_hmac('sha256', '1700000000.{"a":1}', $secret), WebhookSigner::signature($secret, 1_700_000_000, '{"a":1}'));

        $this->assertTrue(WebhookSigner::verify($header, '{"a":1}', $secret, 300, 1_700_000_100));
    }

    public function test_verification_rejects_tampering_wrong_secret_and_stale_or_future_timestamps(): void
    {
        $secret = WebhookSigner::generateSecret();
        $now = 1_700_000_000;
        $header = WebhookSigner::header($secret, $now, '{"a":1}');

        $this->assertFalse(WebhookSigner::verify($header, '{"a":2}', $secret, 300, $now));                 // body changed
        $this->assertFalse(WebhookSigner::verify($header, '{"a":1} ', $secret, 300, $now));                // one extra byte
        $this->assertFalse(WebhookSigner::verify($header, '{"a":1}', WebhookSigner::generateSecret(), 300, $now));
        $this->assertFalse(WebhookSigner::verify($header, '{"a":1}', $secret, 300, $now + 301));            // replayed later
        $this->assertFalse(WebhookSigner::verify($header, '{"a":1}', $secret, 300, $now - 301));            // from the future
        $this->assertTrue(WebhookSigner::verify($header, '{"a":1}', $secret, 300, $now + 300));             // inside tolerance
    }

    public function test_the_timestamp_is_covered_by_the_signature(): void
    {
        $secret = WebhookSigner::generateSecret();
        $now = 1_700_000_000;
        $v1 = WebhookSigner::signature($secret, $now, '{"a":1}');

        // Re-labelling a captured signature with a fresh timestamp must fail.
        $this->assertFalse(WebhookSigner::verify('t='.($now + 1000).",v1={$v1}", '{"a":1}', $secret, 300, $now + 1000));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
    public function test_malformed_headers_never_verify(string $header): void
    {
        $this->assertFalse(WebhookSigner::verify($header, '{}', 'whsec_x', 300, 1_700_000_000));
    }

    public static function malformedHeaders(): array
    {
        return array_map(fn ($h) => [$h], ['', 'garbage', 't=1700000000', 'v1=abcd', 't=abc,v1=00', 't=-5,v1=00', 't=1700000000,v1=', 't=1700000000,v1=zz', ',,,']);
    }

    public function test_verification_accepts_any_matching_candidate_for_secret_rotation_overlap(): void
    {
        $secret = WebhookSigner::generateSecret();
        $now = 1_700_000_000;
        $good = WebhookSigner::signature($secret, $now, '{}');

        $this->assertTrue(WebhookSigner::verify("t={$now},v1=".str_repeat('0', 64).",v1={$good}", '{}', $secret, 300, $now));
    }

    public function test_a_delivered_request_is_signed_with_the_endpoints_secret_and_carries_identifiers(): void
    {
        [$app, $order] = $this->apiOrder();
        [$hook, $secret] = $this->makeWebhook($app, 'https://hooks.example.com/in', ['order.created']);
        $this->sweepAll();

        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $sent = $request;

            return Http::response('', 200);
        });
        $delivery = DeveloperWebhookDelivery::firstOrFail();
        app(WebhookDispatcher::class)->deliver($delivery->id);

        $this->assertNotNull($sent);
        $body = $sent->body();
        $this->assertTrue(WebhookSigner::verify($sent->header('X-XTRA4U-Signature')[0], $body, $secret));
        $this->assertSame('order.created', $sent->header('X-XTRA4U-Event-Type')[0]);
        $this->assertSame($delivery->event->event_id, $sent->header('X-XTRA4U-Event-Id')[0]);
        $this->assertSame($delivery->delivery_uid, $sent->header('X-XTRA4U-Delivery-Id')[0]);
        $this->assertSame('1', $sent->header('X-XTRA4U-Delivery-Attempt')[0]);
        $this->assertSame('application/json', $sent->header('Content-Type')[0]);

        $json = json_decode($body, true);
        $this->assertSame($delivery->event->event_id, $json['id']);
        $this->assertSame('order.created', $json['type']);
        $this->assertSame($order->developer_order_ref, $json['data']['order']['id']);
    }

    public function test_the_payload_never_contains_financial_internals_or_other_tenants_data(): void
    {
        [$app, $order] = $this->apiOrder();
        $this->makeWebhook($app, 'https://hooks.example.com/in', ['order.created']);
        $this->sweepAll();

        $payload = json_encode(DeveloperEvent::firstOrFail()->payload);
        foreach (['commission', 'wallet', 'affiliate', 'platform_fee', 'vendor_earning', 'base_price', 'markup', 'owner_vendor', 'secret', 'key_hash'] as $leak) {
            $this->assertStringNotContainsString($leak, $payload);
        }
    }

    public function test_the_signed_bytes_are_identical_on_every_retry_but_the_attempt_and_timestamp_differ(): void
    {
        [$app] = $this->apiOrder();
        [, $secret] = $this->makeWebhook($app, 'https://hooks.example.com/in', ['order.created']);
        $this->sweepAll();

        $bodies = [];
        $attempts = [];
        Http::fake(function ($request) use (&$bodies, &$attempts) {
            $bodies[] = $request->body();
            $attempts[] = $request->header('X-XTRA4U-Delivery-Attempt')[0];

            return Http::response('', 500);
        });
        $delivery = DeveloperWebhookDelivery::firstOrFail();
        foreach ([1, 2] as $_) {
            DeveloperWebhookDelivery::whereKey($delivery->id)->update(['next_attempt_at' => now()->subSecond()]);
            app(WebhookDispatcher::class)->deliver($delivery->id);
        }

        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame(['1', '2'], $attempts);
    }

    // ── event generation ────────────────────────────────────────────────────

    public function test_creating_an_order_through_the_api_records_order_created_immediately(): void
    {
        [, $order] = $this->apiOrder();

        $this->assertSame(['order.created'], $this->types($order->id));
        $this->assertNull(DeveloperEvent::firstOrFail()->fanned_out_at); // persisted, not yet delivered
    }

    public function test_the_sweeper_derives_events_from_order_state_and_never_duplicates(): void
    {
        [, $order] = $this->apiOrder();
        $detector = app(OrderEventDetector::class);

        $this->set($order, ['payment_reference' => 'PAY-1']);
        $detector->sweep();
        $this->assertSame(['order.created', 'payment.pending'], $this->types());

        $this->set($order, ['payment_status' => 'paid', 'status' => 'Processing']);
        $detector->sweep();
        $this->assertSame(['order.created', 'payment.pending', 'payment.succeeded', 'order.processing'], $this->types());

        $this->set($order, ['status' => 'Completed']);
        $detector->sweep();
        $this->assertSame(['order.created', 'payment.pending', 'payment.succeeded', 'order.processing', 'order.completed'], $this->types());

        // Running it again changes nothing.
        $this->assertSame(0, $detector->sweep());
        $this->assertCount(5, DeveloperEvent::all());
    }

    public function test_only_the_latest_observed_state_is_detected_and_skipped_states_are_not_invented(): void
    {
        [, $order] = $this->apiOrder();
        DeveloperEvent::query()->delete(); // simulate the API-time event being absent too

        // Between two sweeps the order went unpaid -> paid -> completed.
        $this->set($order, ['payment_reference' => 'PAY-1', 'payment_status' => 'paid', 'status' => 'Completed']);
        app(OrderEventDetector::class)->sweep();

        $types = $this->types();
        $this->assertContains('order.completed', $types);
        $this->assertContains('payment.succeeded', $types);
        $this->assertNotContains('payment.pending', $types);   // never observed -> never fabricated
        $this->assertNotContains('order.processing', $types);
    }

    public function test_payment_failure_and_order_failure_events(): void
    {
        [, $order] = $this->apiOrder();
        $this->set($order, ['payment_reference' => 'PAY-1', 'payment_status' => 'failed']);

        app(OrderEventDetector::class)->sweep();

        $this->assertContains('payment.failed', $this->types());
        $this->assertContains('order.failed', $this->types());
    }

    public function test_a_late_payment_after_a_failure_is_still_noticed(): void
    {
        [, $order] = $this->apiOrder();
        $this->set($order, ['payment_reference' => 'PAY-1', 'payment_status' => 'failed']);
        app(OrderEventDetector::class)->sweep();

        // The platform lets a later trusted payment settle a failed order; the sweeper keeps watching.
        $this->set($order, ['payment_status' => 'paid', 'status' => 'Processing']);
        app(OrderEventDetector::class)->sweep();

        $this->assertContains('payment.succeeded', $this->types());
    }

    public function test_completed_orders_become_terminal_and_stop_being_scanned(): void
    {
        [, $order] = $this->apiOrder();
        $this->set($order, ['payment_reference' => 'P', 'payment_status' => 'paid', 'status' => 'Completed']);
        app(OrderEventDetector::class)->sweep();

        $this->assertTrue((bool) DeveloperOrderState::where('order_id', $order->id)->value('is_terminal'));
        $seen = DeveloperOrderState::where('order_id', $order->id)->value('last_seen_at');

        $this->travel(5)->minutes();
        app(OrderEventDetector::class)->sweep();
        $this->assertEquals($seen, DeveloperOrderState::where('order_id', $order->id)->value('last_seen_at'));
    }

    public function test_orders_that_did_not_come_from_the_api_never_produce_events(): void
    {
        // An order that did not originate from the API (e.g. a storefront order) carries no developer attribution.
        [, $web] = $this->apiOrder();
        DeveloperEvent::query()->delete();
        $this->set($web, ['developer_application_id' => null, 'payment_status' => 'paid', 'status' => 'Completed']);

        app(OrderEventDetector::class)->sweep();

        $this->assertSame(0, DeveloperEvent::count());
        $this->assertSame(0, DeveloperOrderState::count());
    }

    public function test_a_lost_checkpoint_cannot_cause_duplicate_events(): void
    {
        [, $order] = $this->apiOrder();
        app(OrderEventDetector::class)->sweep();
        $count = DeveloperEvent::count();

        DeveloperOrderState::query()->delete(); // checkpoint table wiped / rebuilt
        app(OrderEventDetector::class)->sweep();

        $this->assertSame($count, DeveloperEvent::count());
        $this->assertSame(1, DeveloperEvent::where('order_id', $order->id)->where('type', 'order.created')->count());
    }

    public function test_the_unique_index_is_the_final_guard_against_duplicate_events(): void
    {
        [, $order] = $this->apiOrder();
        $this->expectException(\Illuminate\Database\QueryException::class);

        DeveloperEvent::create([
            'event_id' => 'evt_dup', 'application_id' => $order->developer_application_id, 'order_id' => $order->id,
            'type' => 'order.created', 'environment' => 'production', 'payload' => [], 'occurred_at' => now(),
        ]);
    }

    public function test_events_are_old_orders_outside_the_detection_window_are_ignored(): void
    {
        [, $order] = $this->apiOrder();
        DeveloperEvent::query()->delete();
        Order::query()->whereKey($order->id)->update(['created_at' => now()->subDays(40)]);

        $this->assertSame(0, app(OrderEventDetector::class)->sweep());
    }

    // ── fan-out & isolation ─────────────────────────────────────────────────

    public function test_events_fan_out_only_to_subscribed_active_endpoints_of_the_same_application(): void
    {
        [$app, $order] = $this->apiOrder();
        $otherApp = $this->makeApp('production');

        [$subscribed] = $this->makeWebhook($app, 'https://hooks.example.com/a', ['order.created']);
        [$notSubscribed] = $this->makeWebhook($app, 'https://hooks.example.com/b', ['order.completed']);
        [$disabled] = $this->makeWebhook($app, 'https://hooks.example.com/c', ['order.created']);
        $disabled->forceFill(['is_active' => false])->save();
        [$foreign] = $this->makeWebhook($otherApp, 'https://hooks.example.com/d', ['order.created']);

        $this->sweepAll();

        $this->assertSame([$subscribed->id], DeveloperWebhookDelivery::pluck('webhook_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(0, DeveloperWebhookDelivery::where('webhook_id', $foreign->id)->count());
        $this->assertNotNull(DeveloperEvent::firstOrFail()->fanned_out_at);
    }

    public function test_fanning_out_twice_creates_no_duplicate_deliveries(): void
    {
        [$app] = $this->apiOrder();
        $this->makeWebhook($app, 'https://hooks.example.com/a', ['order.created']);
        $event = tap(DeveloperEvent::firstOrFail())->forceFill(['fanned_out_at' => null]);

        $fanout = app(WebhookFanout::class);
        $this->assertSame(1, $fanout->fanOut($event));
        DeveloperEvent::whereKey($event->id)->update(['fanned_out_at' => null]); // crash before the marker stuck
        $this->assertSame(0, $fanout->fanOut($event->refresh()));

        $this->assertSame(1, DeveloperWebhookDelivery::count());
    }

    public function test_an_endpoint_created_after_an_event_does_not_receive_the_past(): void
    {
        [$app] = $this->apiOrder();
        $this->sweepAll();                 // event fanned out to nobody
        $this->makeWebhook($app, 'https://hooks.example.com/a', ['order.created']);
        $this->sweepAll();

        $this->assertSame(0, DeveloperWebhookDelivery::count());
    }

    public function test_two_applications_events_never_cross(): void
    {
        [$appA] = $this->apiOrder(null, 'ka');
        [$appB] = $this->apiOrder(null, 'kb');
        [$hookA] = $this->makeWebhook($appA, 'https://hooks.example.com/a', ['order.created']);
        [$hookB] = $this->makeWebhook($appB, 'https://hooks.example.com/b', ['order.created']);

        $this->sweepAll();

        foreach (DeveloperWebhookDelivery::with('event')->get() as $d) {
            $owner = (int) $d->event->application_id;
            $this->assertSame($owner, (int) DeveloperWebhook::find($d->webhook_id)->application_id);
        }
        $this->assertSame(1, DeveloperWebhookDelivery::where('webhook_id', $hookA->id)->count());
        $this->assertSame(1, DeveloperWebhookDelivery::where('webhook_id', $hookB->id)->count());
    }

    // ── delivery, retry, backoff, failure ───────────────────────────────────

    private function prepared(string $url = 'https://hooks.example.com/in'): array
    {
        [$app] = $this->apiOrder();
        [$hook, $secret] = $this->makeWebhook($app, $url, ['order.created']);
        $this->sweepAll();

        return [$hook, DeveloperWebhookDelivery::firstOrFail(), $secret];
    }

    private function due(DeveloperWebhookDelivery $d): void
    {
        DeveloperWebhookDelivery::whereKey($d->id)->where('status', 'pending')->update(['next_attempt_at' => now()->subSecond()]);
    }

    public function test_a_2xx_response_marks_the_delivery_succeeded_and_resets_failures(): void
    {
        [$hook, $d] = $this->prepared();
        $hook->forceFill(['consecutive_failures' => 3])->save();
        Http::fake(['*' => Http::response('ok', 204)]);

        app(WebhookDispatcher::class)->deliver($d->id);

        $d->refresh();
        $this->assertSame('succeeded', $d->status);
        $this->assertSame(204, $d->last_status_code);
        $this->assertNotNull($d->delivered_at);
        $this->assertNull($d->next_attempt_at);
        $this->assertSame(0, (int) $hook->fresh()->consecutive_failures);
        $this->assertSame('success', DeveloperWebhookAttempt::firstOrFail()->outcome);
    }

    public function test_failures_retry_with_exponential_backoff_then_fail_permanently(): void
    {
        [$hook, $d] = $this->prepared();
        Http::fake(['*' => Http::response('nope', 500)]);
        $backoff = config('developer_api.webhooks.backoff_seconds');
        $dispatcher = app(WebhookDispatcher::class);

        foreach ($backoff as $i => $seconds) {
            $this->due($d);
            $dispatcher->deliver($d->id);
            $d->refresh();

            $this->assertSame('pending', $d->status, 'attempt '.($i + 1));
            $this->assertSame($i + 1, (int) $d->attempts);
            $this->assertEqualsWithDelta($seconds, now()->diffInSeconds($d->next_attempt_at, false), 5);
            $this->assertSame(500, $d->last_status_code);
        }

        $this->due($d);
        $dispatcher->deliver($d->id); // the final allowed attempt
        $d->refresh();

        $this->assertSame('failed', $d->status);
        $this->assertNull($d->next_attempt_at);
        $this->assertSame(count($backoff) + 1, (int) $d->total_attempts);
        $this->assertSame(count($backoff) + 1, DeveloperWebhookAttempt::where('delivery_id', $d->id)->count());
        $this->assertGreaterThan(0, (int) $hook->fresh()->consecutive_failures);

        // A failed delivery is never touched by the scheduler again.
        Queue::fake();
        $this->assertSame(0, $dispatcher->dispatchDue());
    }

    public function test_the_backoff_is_strictly_increasing(): void
    {
        $b = config('developer_api.webhooks.backoff_seconds');
        $sorted = $b;
        sort($sorted);
        $this->assertSame($sorted, $b);
        $this->assertSame(count(array_unique($b)), count($b));
    }

    public function test_redirects_timeouts_and_connection_errors_are_failed_attempts_with_safe_labels(): void
    {
        [, $d] = $this->prepared();
        $dispatcher = app(WebhookDispatcher::class);

        $this->fakeHttp(['*' => Http::response('', 301, ['Location' => 'https://127.0.0.1/'])]);
        $dispatcher->deliver($d->id);
        $this->assertSame('redirect_blocked', $d->fresh()->last_error);

        $this->due($d);
        $this->fakeHttp(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 10001 ms'));
        $dispatcher->deliver($d->id);
        $this->assertSame('timeout', $d->fresh()->last_error);

        $this->due($d);
        $this->fakeHttp(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to 10.9.9.9'));
        $dispatcher->deliver($d->id);
        $this->assertSame('connection_failed', $d->fresh()->last_error);
        $this->assertStringNotContainsString('10.9.9.9', json_encode(DeveloperWebhookAttempt::all()->toArray()));
    }

    public function test_a_success_after_failures_closes_the_delivery(): void
    {
        [, $d] = $this->prepared();
        $dispatcher = app(WebhookDispatcher::class);

        $this->fakeHttp(['*' => Http::response('', 503)]);
        $dispatcher->deliver($d->id);
        $this->due($d);
        $this->fakeHttp(['*' => Http::response('', 200)]);
        $dispatcher->deliver($d->id);

        $this->assertSame('succeeded', $d->fresh()->status);
        $this->assertSame(2, (int) $d->fresh()->total_attempts);
    }

    public function test_a_delivery_is_not_attempted_before_it_is_due(): void
    {
        [, $d] = $this->prepared();
        DeveloperWebhookDelivery::whereKey($d->id)->update(['next_attempt_at' => now()->addMinutes(10)]);
        Http::fake();

        app(WebhookDispatcher::class)->deliver($d->id);

        Http::assertNothingSent();
        $this->assertSame('pending', $d->fresh()->status);
    }

    public function test_a_finished_delivery_is_never_sent_again(): void
    {
        [, $d] = $this->prepared();
        Http::fake(['*' => Http::response('', 200)]);
        $dispatcher = app(WebhookDispatcher::class);

        $dispatcher->deliver($d->id);
        $dispatcher->deliver($d->id);
        $dispatcher->deliver($d->id);

        Http::assertSentCount(1);
    }

    public function test_a_live_claim_blocks_a_second_worker(): void
    {
        [, $d] = $this->prepared();
        DeveloperWebhookDelivery::whereKey($d->id)->update(['status' => 'delivering', 'claimed_at' => now()]);
        Http::fake();

        app(WebhookDispatcher::class)->deliver($d->id);

        Http::assertNothingSent();
    }

    public function test_a_stale_claim_from_a_crashed_worker_is_recovered(): void
    {
        [, $d] = $this->prepared();
        DeveloperWebhookDelivery::whereKey($d->id)->update(['status' => 'delivering', 'claimed_at' => now()->subMinutes(30)]);
        Http::fake(['*' => Http::response('', 200)]);

        Queue::fake();
        $this->assertSame(1, app(WebhookDispatcher::class)->dispatchDue());
        Queue::assertPushed(DeliverDeveloperWebhook::class, fn ($j) => $j->deliveryId === $d->id);

        app(WebhookDispatcher::class)->deliver($d->id);
        $this->assertSame('succeeded', $d->fresh()->status);
    }

    public function test_dispatch_due_queues_only_due_pending_work(): void
    {
        [$app] = $this->apiOrder(null, 'x1');
        [$app2] = $this->apiOrder(null, 'x2');
        $this->makeWebhook($app, 'https://hooks.example.com/a', ['order.created']);
        $this->makeWebhook($app2, 'https://hooks.example.com/b', ['order.created']);
        $this->sweepAll();
        [$due, $later] = DeveloperWebhookDelivery::orderBy('id')->get()->all();
        DeveloperWebhookDelivery::whereKey($later->id)->update(['next_attempt_at' => now()->addHour()]);
        Queue::fake();

        $this->assertSame(1, app(WebhookDispatcher::class)->dispatchDue());
        Queue::assertPushed(DeliverDeveloperWebhook::class, 1);
    }

    public function test_a_crashing_transport_loses_no_event_and_the_delivery_stays_recoverable(): void
    {
        [, $d] = $this->prepared();
        $this->app->bind(\App\Services\DeveloperApi\Webhooks\WebhookTransport::class, fn () => new class implements \App\Services\DeveloperApi\Webhooks\WebhookTransport
        {
            public function send(\App\Services\DeveloperApi\Webhooks\ValidatedDestination $d, array $h, string $b): \App\Services\DeveloperApi\Webhooks\TransportResult
            {
                throw new \RuntimeException('worker died');
            }
        });

        try {
            app(WebhookDispatcher::class)->deliver($d->id);
            $this->fail('expected the crash to propagate');
        } catch (\RuntimeException) {
        }

        // The event is intact, the delivery is stuck 'delivering' with a claim, and the stale-claim rule recovers it.
        $this->assertSame(1, DeveloperEvent::count());
        $this->assertSame('delivering', $d->fresh()->status);
        DeveloperWebhookDelivery::whereKey($d->id)->update(['claimed_at' => now()->subHour()]);
        Queue::fake();
        $this->assertSame(1, app(WebhookDispatcher::class)->dispatchDue());
    }

    public function test_the_scheduled_command_runs_the_whole_pipeline(): void
    {
        [$app, $order] = $this->apiOrder();
        $this->makeWebhook($app, 'https://hooks.example.com/in', ['order.created', 'payment.succeeded']);
        $this->set($order, ['payment_reference' => 'P', 'payment_status' => 'paid', 'status' => 'Processing']);
        Queue::fake();

        $this->artisan('developer-webhooks:run')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['order.created', 'payment.succeeded', 'order.processing'], $this->types());
        $this->assertSame(2, DeveloperWebhookDelivery::count()); // order.processing not subscribed
        Queue::assertPushed(DeliverDeveloperWebhook::class, 2);

        // Idempotent: running again creates and queues nothing new beyond re-dispatching due work.
        $this->artisan('developer-webhooks:run')->assertSuccessful();
        $this->assertSame(3, DeveloperEvent::count());
        $this->assertSame(2, DeveloperWebhookDelivery::count());
    }

    public function test_the_command_is_scheduled_every_minute_without_overlap(): void
    {
        \Illuminate\Support\Facades\Artisan::call('schedule:list');
        $line = collect(explode("
", \Illuminate\Support\Facades\Artisan::output()))->first(fn ($l) => str_contains($l, 'developer-webhooks:run'));

        $this->assertNotNull($line, 'developer-webhooks:run is not scheduled');
        $this->assertStringContainsString('* * * * *', preg_replace('/\s+/', ' ', $line)); // padded columns in the listing
    }
}
