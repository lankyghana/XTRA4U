<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookAttempt;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Services\DeveloperApi\Webhooks\IpGuard;
use App\Services\DeveloperApi\Webhooks\PinnedWebhookTransport;
use App\Services\DeveloperApi\Webhooks\UnsafeWebhookDestination;
use App\Services\DeveloperApi\Webhooks\ValidatedDestination;
use App\Services\DeveloperApi\Webhooks\WebhookDispatcher;
use App\Services\DeveloperApi\Webhooks\WebhookService;
use App\Services\DeveloperApi\Webhooks\WebhookUrlValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SSRF protection. No test here touches a real resolver or a real network: DNS is a
 * fake, and HTTP is faked with stray requests prevented.
 */
class WebhookSsrfTest extends PortalTestCase
{
    private function validator(): WebhookUrlValidator
    {
        return app(WebhookUrlValidator::class);
    }

    private function assertRejected(string $url, ?string $reason = null): void
    {
        try {
            $this->validator()->validate($url);
            $this->fail("Expected {$url} to be rejected");
        } catch (UnsafeWebhookDestination $e) {
            if ($reason !== null) {
                $this->assertSame($reason, $e->reason, "Wrong rejection reason for {$url}");
            }
            $this->assertTrue(true);
        }
    }

    // ── IpGuard: the address classifier ─────────────────────────────────────

    #[DataProvider('unsafeIps')]
    public function test_ip_guard_rejects_non_public_addresses(string $ip): void
    {
        $this->assertFalse(IpGuard::isPublic($ip), "{$ip} must not be treated as public");
    }

    public static function unsafeIps(): array
    {
        return array_map(fn ($ip) => [$ip], [
            // loopback
            '127.0.0.1', '127.1.2.3', '127.255.255.255',
            // "this" network / unspecified
            '0.0.0.0', '0.1.2.3',
            // RFC 1918
            '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.0.1', '192.168.255.255',
            // link-local incl. cloud metadata
            '169.254.0.1', '169.254.169.254', '169.254.170.2',
            // CGNAT, special-use, test nets, benchmarking
            '100.64.0.1', '100.127.255.255', '192.0.0.1', '192.0.2.1', '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1', '192.88.99.1',
            // multicast, reserved, broadcast
            '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255',
            // IPv6: loopback, unspecified, ULA (incl. AWS IMDS v6), link-local, multicast
            '::1', '::', 'fc00::1', 'fd00:ec2::254', 'fdff::1', 'fe80::1', 'febf::1', 'ff02::1', 'ff00::',
            // IPv6: IPv4-mapped / compatible / NAT64 wrapping private v4
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::127.0.0.1', '64:ff9b::7f00:1',
            // IPv6: documentation, Teredo, 6to4, ORCHID
            '2001:db8::1', '2001::1', '2002:7f00:1::1', '2001:10::1',
            // garbage
            'not-an-ip', '', '999.1.1.1', '1.2.3', '1.2.3.4.5', '0x7f.0.0.1',
        ]);
    }

    #[DataProvider('safeIps')]
    public function test_ip_guard_accepts_public_addresses(string $ip): void
    {
        $this->assertTrue(IpGuard::isPublic($ip));
    }

    public static function safeIps(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1',
            '169.253.255.255', '169.255.0.1', '2606:4700:4700::1111', '2a00:1450:4001:81b::200e',
        ]);
    }

    // ── URL validator: structure ────────────────────────────────────────────

    #[DataProvider('structurallyBadUrls')]
    public function test_structurally_unsafe_urls_are_rejected(string $url, string $reason): void
    {
        $this->assertRejected($url, $reason);
    }

    public static function structurallyBadUrls(): array
    {
        return [
            'http scheme' => ['http://hooks.example.com/in', 'https_required'],
            'ftp scheme' => ['ftp://hooks.example.com/in', 'https_required'],
            'file scheme' => ['file:///etc/passwd', 'invalid_url'],
            'gopher' => ['gopher://hooks.example.com/_x', 'https_required'],
            'javascript' => ['javascript:alert(1)', 'invalid_url'],
            'no scheme' => ['hooks.example.com/in', 'invalid_url'],
            'empty' => ['', 'invalid_url'],
            'credentials' => ['https://user:pass@hooks.example.com/in', 'credentials_in_url'],
            'user only' => ['https://admin@hooks.example.com/in', 'credentials_in_url'],
            'custom port' => ['https://hooks.example.com:8443/in', 'port_not_allowed'],
            'redis port' => ['https://hooks.example.com:6379/in', 'port_not_allowed'],
            'port 80' => ['https://hooks.example.com:80/in', 'port_not_allowed'],
            'inner space' => ['https://hooks.example.com/a b', 'invalid_url'],
            'backslash trick' => ['https://hooks.example.com\\@127.0.0.1/', 'invalid_url'],
            'too long' => ['https://hooks.example.com/'.str_repeat('a', 2100), 'invalid_url'],
            'unicode host' => ['https://hôoks.example.com/in', 'invalid_host'],
            'single label' => ['https://intranet/in', 'invalid_host'],
            'underscore' => ['https://bad_host.example.com/', 'invalid_host'],
        ];
    }

    #[DataProvider('ipLiteralHosts')]
    public function test_ip_literals_in_every_notation_are_rejected_without_any_dns_lookup(string $url): void
    {
        $this->assertRejected($url);
        $this->assertSame([], $this->dns->lookups, 'No DNS lookup may happen for an IP-literal host');
    }

    public function test_surrounding_whitespace_is_trimmed_and_the_trimmed_url_is_what_is_used(): void
    {
        $d = $this->validator()->validate("  https://hooks.example.com/in
");

        $this->assertSame('https://hooks.example.com/in', $d->url);
    }

    public static function ipLiteralHosts(): array
    {
        return array_map(fn ($u) => [$u], [
            'https://127.0.0.1/', 'https://8.8.8.8/', 'https://169.254.169.254/latest/meta-data/',
            'https://10.0.0.5/', 'https://192.168.1.1/',
            'https://[::1]/', 'https://[fd00:ec2::254]/', 'https://[2606:4700:4700::1111]/', 'https://[::ffff:127.0.0.1]/',
            // decimal / hex / octal / short forms that curl or glibc would resolve to a loopback
            'https://2130706433/', 'https://0x7f000001/', 'https://0x7f.0.0.1/', 'https://017700000001/', 'https://127.1/',
            'https://0/', 'https://127.0.1/', 'https://2852039166/',
        ]);
    }

    #[DataProvider('internalNames')]
    public function test_internal_and_metadata_hostnames_are_rejected(string $host): void
    {
        // Even if DNS "resolved" them to a public address the name itself is refused.
        $this->dns->set($host, '8.8.8.8');
        $this->assertRejected("https://{$host}/hook"); // single labels fail the host shape, dotted ones the deny-list
    }

    public static function internalNames(): array
    {
        return array_map(fn ($h) => [$h], [
            'localhost', 'foo.localhost', 'metadata.google.internal', 'metadata', 'instance-data',
            'server.internal', 'db.local', 'printer.lan', 'nas.home', 'x.corp', 'svc.cluster', 'a.svc',
            'kubernetes.default', 'host.localdomain', 'api.test', 'foo.example', 'x.invalid', 'abc.onion',
            '1.0.0.127.in-addr.arpa',
        ]);
    }

    public function test_a_trailing_dot_or_mixed_case_does_not_bypass_the_name_checks(): void
    {
        $this->dns->set('localhost', '127.0.0.1');
        $this->assertRejected('https://LOCALHOST/');
        $this->assertRejected('https://localhost./');
        $this->dns->set('db.internal', '8.8.8.8');
        $this->assertRejected('https://DB.Internal/', 'blocked_host');
        $this->assertRejected('https://db.internal./', 'blocked_host');
        $this->assertRejected('https://Metadata.Google.Internal./', 'blocked_host');
    }

    // ── URL validator: DNS answers ──────────────────────────────────────────

    #[DataProvider('privateAnswers')]
    public function test_a_public_looking_name_that_resolves_to_a_private_address_is_rejected(string|array $answer): void
    {
        $this->dns->set('evil.example.net', $answer);
        $this->assertRejected('https://evil.example.net/hook', 'non_public_address');
    }

    public static function privateAnswers(): array
    {
        return [
            'loopback' => ['127.0.0.1'], 'rfc1918 10' => ['10.1.2.3'], 'rfc1918 172' => ['172.20.0.9'],
            'rfc1918 192' => ['192.168.0.10'], 'metadata' => ['169.254.169.254'], 'cgnat' => ['100.64.1.1'],
            'v6 loopback' => ['::1'], 'v6 ula' => ['fd12:3456::1'], 'v6 link local' => ['fe80::1'],
            'v6 mapped loopback' => ['::ffff:127.0.0.1'], 'unspecified' => ['0.0.0.0'],
            'garbage answer' => ['not-an-ip'],
        ];
    }

    public function test_every_resolved_address_must_be_public_not_just_the_first(): void
    {
        $this->dns->set('mixed.example.net', ['93.184.216.34', '169.254.169.254']);
        $this->assertRejected('https://mixed.example.net/hook', 'non_public_address');

        $this->dns->set('mixed6.example.net', ['93.184.216.34', 'fd00:ec2::254']);
        $this->assertRejected('https://mixed6.example.net/hook', 'non_public_address');

        $this->dns->set('mixed-last.example.net', ['8.8.8.8', '1.1.1.1', '10.0.0.1']);
        $this->assertRejected('https://mixed-last.example.net/hook', 'non_public_address');
    }

    public function test_unresolvable_hosts_fail_closed(): void
    {
        $this->assertRejected('https://does-not-exist.example.net/hook', 'dns_unresolvable');
        $this->dns->set('empty.example.net', []);
        $this->assertRejected('https://empty.example.net/hook', 'dns_unresolvable');
    }

    public function test_the_rejection_message_does_not_reveal_the_internal_address(): void
    {
        $this->dns->set('probe.example.net', '169.254.169.254');

        try {
            $this->validator()->validate('https://probe.example.net/');
            $this->fail('should be rejected');
        } catch (UnsafeWebhookDestination $e) {
            $this->assertStringNotContainsString('169.254', $e->getMessage());
            $this->assertStringNotContainsString('169.254', $e->reason);
        }
    }

    public function test_a_valid_public_https_host_is_accepted_and_pinned_to_a_validated_ip(): void
    {
        $this->dns->set('ok.example.net', ['2606:4700:4700::1111', '93.184.216.34']);

        $d = $this->validator()->validate('https://ok.example.net/hooks/xtra4u?x=1');

        $this->assertSame('ok.example.net', $d->host);
        $this->assertSame(443, $d->port);
        $this->assertSame('93.184.216.34', $d->pinnedIp); // IPv4 preferred
        $this->assertContains($d->pinnedIp, $d->ips);
        $this->assertTrue(IpGuard::isPublic($d->pinnedIp));
    }

    // ── registration enforces it ────────────────────────────────────────────

    public function test_registration_through_the_service_rejects_unsafe_urls(): void
    {
        $app = $this->sandboxApp($this->developer());

        foreach (['https://127.0.0.1/x', 'http://hooks.example.com/x', 'https://localhost/x', 'https://169.254.169.254/x'] as $url) {
            try {
                app(WebhookService::class)->create($app, 'test', $url, ['order.created'], null);
                $this->fail("{$url} should be rejected");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('url', $e->errors());
            }
        }
        $this->assertSame(0, DeveloperWebhook::count());
    }

    public function test_registration_through_the_portal_rejects_unsafe_urls(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $this->dns->set('rebind.example.net', '10.0.0.7');

        foreach (['https://127.0.0.1/x', 'https://rebind.example.net/x', 'http://hooks.example.com/x', 'https://[::1]/x'] as $url) {
            $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks", ['url' => $url, 'events' => ['order.created']])
                ->assertSessionHasErrors('url');
        }
        $this->assertSame(0, DeveloperWebhook::count());
    }

    public function test_updating_a_url_to_an_unsafe_one_is_rejected_and_the_old_url_is_kept(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);

        $this->actingAsDeveloper($dev)->put("/developers/applications/{$app->id}/webhooks/{$hook->id}", [
            'url' => 'https://169.254.169.254/latest', 'events' => ['order.created'],
        ])->assertSessionHasErrors('url');

        $this->assertSame('https://hooks.example.com/in', $hook->fresh()->url);
    }

    public function test_re_enabling_a_webhook_re_validates_its_destination(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$hook] = $this->makeWebhook($app);
        $hook->forceFill(['is_active' => false])->save();

        $this->dns->set('hooks.example.com', '10.0.0.9'); // DNS changed while it was disabled

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/toggle")->assertSessionHasErrors('url');
        $this->assertFalse($hook->fresh()->is_active);
    }

    // ── delivery re-validates (DNS rebinding) ───────────────────────────────

    private function seedDelivery(DeveloperWebhook $hook): DeveloperWebhookDelivery
    {
        $event = DeveloperEvent::create([
            'event_id' => 'evt_'.uniqid(), 'application_id' => $hook->application_id, 'order_id' => 1,
            'type' => 'order.created', 'environment' => 'production',
            'payload' => ['order' => ['id' => 'ord_x']], 'occurred_at' => now(), 'fanned_out_at' => now(),
        ]);

        return DeveloperWebhookDelivery::create([
            'delivery_uid' => 'dlv_'.uniqid(), 'webhook_id' => $hook->id, 'event_id' => $event->id,
            'status' => 'pending', 'next_attempt_at' => now()->subSecond(),
        ]);
    }

    public function test_dns_rebinding_between_registration_and_delivery_blocks_the_request(): void
    {
        $app = $this->makeApp('production');
        [$hook] = $this->makeWebhook($app); // validated while public
        $delivery = $this->seedDelivery($hook);

        // The attacker's DNS now answers with the cloud metadata address.
        $this->dns->set('hooks.example.com', '169.254.169.254');
        Http::fake(); // would record any request that slipped through

        app(WebhookDispatcher::class)->deliver($delivery->id);

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame('pending', $delivery->status); // scheduled for retry, not delivered
        $this->assertSame('blocked', DeveloperWebhookAttempt::firstOrFail()->outcome);
        $this->assertNull($delivery->delivered_at);
    }

    public function test_dns_flipping_to_a_mixed_answer_at_delivery_time_is_blocked(): void
    {
        $app = $this->makeApp('production');
        [$hook] = $this->makeWebhook($app);
        $delivery = $this->seedDelivery($hook);

        $this->dns->set('hooks.example.com', ['93.184.216.34', '127.0.0.1']);
        Http::fake();

        app(WebhookDispatcher::class)->deliver($delivery->id);

        Http::assertNothingSent();
        $this->assertSame('blocked', DeveloperWebhookAttempt::firstOrFail()->outcome);
    }

    public function test_dns_failure_at_delivery_time_fails_closed(): void
    {
        $app = $this->makeApp('production');
        [$hook] = $this->makeWebhook($app);
        $delivery = $this->seedDelivery($hook);

        $this->dns->set('hooks.example.com', []);
        Http::fake();

        app(WebhookDispatcher::class)->deliver($delivery->id);

        Http::assertNothingSent();
        $this->assertSame('blocked', DeveloperWebhookAttempt::firstOrFail()->outcome);
    }

    public function test_validation_runs_again_for_every_single_attempt(): void
    {
        $app = $this->makeApp('production');
        [$hook] = $this->makeWebhook($app);
        $delivery = $this->seedDelivery($hook);
        Http::fake(['*' => Http::response('', 500)]);

        $before = count($this->dns->lookups);
        app(WebhookDispatcher::class)->deliver($delivery->id);
        $afterFirst = count($this->dns->lookups);
        // Query-level update: the in-memory model is stale after the first attempt.
        DeveloperWebhookDelivery::whereKey($delivery->id)->update(['next_attempt_at' => now()->subSecond()]);
        app(WebhookDispatcher::class)->deliver($delivery->id);

        $this->assertGreaterThan($before, $afterFirst);
        $this->assertGreaterThan($afterFirst, count($this->dns->lookups));
    }

    public function test_a_disabled_or_deleted_endpoint_receives_nothing(): void
    {
        $app = $this->makeApp('production');
        [$hook] = $this->makeWebhook($app);
        $delivery = $this->seedDelivery($hook);
        $hook->forceFill(['is_active' => false])->save();
        Http::fake();

        app(WebhookDispatcher::class)->deliver($delivery->id);

        Http::assertNothingSent();
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    // ── the real transport: pinning, redirects, limits ──────────────────────

    private function destination(string $ip = '93.184.216.34', string $host = 'hooks.example.com'): ValidatedDestination
    {
        return new ValidatedDestination("https://{$host}/in", $host, 443, [$ip], $ip);
    }

    public function test_the_outbound_connection_is_pinned_to_the_validated_ip(): void
    {
        $opts = (new PinnedWebhookTransport)->options($this->destination('93.184.216.34'));

        $this->assertSame(['hooks.example.com:443:93.184.216.34'], $opts['curl'][CURLOPT_RESOLVE]);
    }

    public function test_ipv6_pins_use_bracket_notation(): void
    {
        $opts = (new PinnedWebhookTransport)->options($this->destination('2606:4700:4700::1111'));

        $this->assertSame(['hooks.example.com:443:[2606:4700:4700::1111]'], $opts['curl'][CURLOPT_RESOLVE]);
    }

    public function test_transport_options_forbid_redirects_proxies_and_non_https_and_cap_the_response(): void
    {
        $opts = (new PinnedWebhookTransport)->options($this->destination());

        $this->assertFalse($opts['allow_redirects']);
        $this->assertTrue($opts['verify']);
        $this->assertSame('', $opts['proxy']);
        $this->assertGreaterThan(0, $opts['timeout']);
        $this->assertGreaterThan(0, $opts['connect_timeout']);
        $this->assertLessThanOrEqual(30, $opts['timeout']);

        $curl = $opts['curl'];
        $this->assertSame(CURLPROTO_HTTPS, $curl[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTPS, $curl[CURLOPT_REDIR_PROTOCOLS]);
        $this->assertFalse($curl[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $curl[CURLOPT_MAXREDIRS]);
        $this->assertSame('*', $curl[CURLOPT_NOPROXY]);
        $this->assertSame(config('developer_api.webhooks.max_response_bytes'), $curl[CURLOPT_MAXFILESIZE]);

        $limit = (int) config('developer_api.webhooks.max_response_bytes');
        $this->assertSame(0, $curl[CURLOPT_PROGRESSFUNCTION](null, 0, $limit));
        $this->assertSame(1, $curl[CURLOPT_PROGRESSFUNCTION](null, 0, $limit + 1)); // abort an oversize stream
    }

    public function test_the_options_actually_reach_the_http_client_for_a_real_send(): void
    {
        $seen = null;
        Http::fake(function ($request, $options) use (&$seen) {
            $seen = ['url' => $request->url(), 'options' => $options];

            return Http::response('ok', 200);
        });

        $result = (new PinnedWebhookTransport)->send($this->destination(), ['X-Test' => '1'], '{"a":1}');

        $this->assertTrue($result->succeeded());
        $this->assertSame('https://hooks.example.com/in', $seen['url']);
        $this->assertSame(['hooks.example.com:443:93.184.216.34'], $seen['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($seen['options']['allow_redirects']);
        $this->assertSame('', $seen['options']['proxy']);
    }

    public function test_a_redirect_response_is_a_failed_delivery_and_is_never_followed(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('SECRET', 200),
        ]);

        $result = (new PinnedWebhookTransport)->send($this->destination(), [], '{}');

        $this->assertFalse($result->succeeded());
        $this->assertSame('redirect_blocked', $result->outcome);
        $this->assertSame(302, $result->statusCode);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_every_3xx_is_treated_as_a_redirect_failure(): void
    {
        foreach ([301, 303, 307, 308] as $status) {
            Http::fake(['*' => Http::response('', $status, ['Location' => 'https://127.0.0.1/'])]);
            $this->assertSame('redirect_blocked', (new PinnedWebhookTransport)->send($this->destination(), [], '{}')->outcome);
        }
    }

    public function test_the_response_body_is_never_stored_or_returned(): void
    {
        Http::fake(['*' => Http::response(str_repeat('SECRETBODY', 100), 500)]);

        $result = (new PinnedWebhookTransport)->send($this->destination(), [], '{}');

        $this->assertSame('http_error', $result->outcome);
        $this->assertStringNotContainsString('SECRETBODY', json_encode((array) $result));
        $this->assertSame(['outcome', 'statusCode', 'durationMs', 'error'], array_keys((array) $result));
    }

    public function test_connection_errors_are_classified_without_leaking_raw_exception_text(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out after 5000 milliseconds for https://10.1.2.3/secret'));

        $result = (new PinnedWebhookTransport)->send($this->destination(), [], '{}');

        $this->assertSame('timeout', $result->outcome);
        $this->assertStringNotContainsString('10.1.2.3', (string) $result->error);
    }
}
