<?php

namespace Tests\Feature\Ai;

use App\Support\ClientIpKey;
use App\Support\TrustedProxies;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Ai\CustomerAssistant\CustomerAssistantTestCase;

/**
 * Trusted-proxy handling and the per-IP limiter built on it. The proxy ranges used here are RFC 5737 / RFC 1918
 * documentation and private examples: no production proxy address is known to, or guessed by, this repository.
 */
class TrustedProxiesTest extends CustomerAssistantTestCase
{
    private const CLIENT = '198.51.100.7';

    private const PROXY = '10.1.2.3';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn (Request $request) => response()->json(['ip' => $request->ip(), 'key' => ClientIpKey::of($request->ip()), 'secure' => $request->isSecure(), 'host' => $request->getHost()]));

        $this->mockProvider()->respondWith('ok');
    }

    /** @return array<string,mixed> */
    private function whoAmI(string $remote, array $headers = []): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->getJson('/_test/ip', $headers)->assertOk()->json();
    }

    private function trust(array $proxies): void
    {
        config(['trustedproxy.proxies' => $proxies]);
    }

    // ------------------------------------------------------------------------------------------ parsing

    public function test_parsing_accepts_only_literal_addresses_and_bounded_ranges(): void
    {
        $parsed = TrustedProxies::parse(' 203.0.113.9 , 10.1.0.0/16,2001:db8::1, 2001:db8::/32 ,, 10.1.0.0/16 ');

        $this->assertSame(['203.0.113.9', '10.1.0.0/16', '2001:db8::1', '2001:db8::/32'], $parsed['proxies']);
        $this->assertSame(0, $parsed['rejected']);
    }

    public function test_wildcards_everyone_ranges_hostnames_and_garbage_are_rejected_and_never_half_trusted(): void
    {
        foreach (['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0', '0.0.0.0/7', '10.0.0.0/7', '2001:db8::/15', 'proxy.example.com', '10.1.2.3/33', '10.1.2.3/abc', '10.1.2.3/8/8', '999.1.1.1', '10.1.2.3/-1', '10.1.2.4/24x', 'true', '1'] as $bad) {
            $parsed = TrustedProxies::parse($bad);
            $this->assertSame([], $parsed['proxies'], "[{$bad}] must not be trusted");
            $this->assertSame(1, $parsed['rejected'], "[{$bad}] is counted as rejected");
        }

        // A bad entry never invalidates (or widens) its valid neighbours.
        $mixed = TrustedProxies::parse('*, 203.0.113.9, 0.0.0.0/0');
        $this->assertSame(['203.0.113.9'], $mixed['proxies']);
        $this->assertSame(2, $mixed['rejected']);
    }

    public function test_unset_empty_and_non_string_values_trust_nobody(): void
    {
        foreach ([null, '', '   ', ',,', false, [], 0] as $value) {
            $this->assertSame(['proxies' => [], 'rejected' => 0], TrustedProxies::parse($value), var_export($value, true));
        }
    }

    public function test_the_shipped_config_trusts_nobody_unless_the_operator_sets_it(): void
    {
        $shipped = require config_path('trustedproxy.php');

        $this->assertSame(array_keys($shipped), ['proxies', 'rejected']);
        $this->assertSame([], TrustedProxies::parse(null)['proxies']);
        $this->assertStringNotContainsString('*', json_encode($shipped['proxies']));
        // Nothing in the repository ships a concrete proxy address.
        $this->assertDoesNotMatchRegularExpression('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', (string) file_get_contents(config_path('trustedproxy.php')));
    }

    // ------------------------------------------------------------------------------------ real requests

    public function test_a_direct_connection_is_the_client_and_forwarded_headers_from_it_are_ignored(): void
    {
        $this->trust([]);

        $this->assertSame(self::CLIENT, $this->whoAmI(self::CLIENT)['ip']);

        $spoofed = $this->whoAmI(self::CLIENT, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Port' => '443']);

        $this->assertSame(self::CLIENT, $spoofed['ip'], 'nobody is trusted, so the header is just data');
        $this->assertFalse($spoofed['secure']);
        $this->assertNotSame('evil.example', $spoofed['host']);
    }

    public function test_a_request_from_an_untrusted_address_cannot_spoof_its_ip_even_when_a_proxy_list_is_configured(): void
    {
        $this->trust(['10.1.0.0/16']);

        $this->assertSame(self::CLIENT, $this->whoAmI(self::CLIENT, ['X-Forwarded-For' => '1.2.3.4'])['ip']);
        $this->assertSame('203.0.113.99', $this->whoAmI('203.0.113.99', ['X-Forwarded-For' => '10.1.2.3'])['ip'], 'claiming to be behind a trusted proxy changes nothing');
    }

    public function test_a_trusted_proxy_passes_the_real_client_address_through(): void
    {
        $this->trust(['10.1.0.0/16']);

        $seen = $this->whoAmI(self::PROXY, ['X-Forwarded-For' => self::CLIENT, 'X-Forwarded-Proto' => 'https']);

        $this->assertSame(self::CLIENT, $seen['ip']);
        $this->assertTrue($seen['secure']);
        $this->assertSame(self::CLIENT, $seen['key']);
    }

    public function test_a_trusted_proxy_without_a_forwarded_header_is_itself_the_client(): void
    {
        $this->trust(['10.1.0.0/16']);

        $this->assertSame(self::PROXY, $this->whoAmI(self::PROXY)['ip']);
    }

    public function test_a_client_prepended_forwarded_address_is_ignored_behind_a_trusted_proxy(): void
    {
        $this->trust(['10.1.0.0/16']);

        // The client wrote "6.6.6.6"; the proxy appended the address it actually saw. The rightmost untrusted hop wins.
        $this->assertSame(self::CLIENT, $this->whoAmI(self::PROXY, ['X-Forwarded-For' => '6.6.6.6, '.self::CLIENT])['ip']);
        // A chain of two trusted proxies still resolves to the first address that is not one of ours.
        $this->assertSame(self::CLIENT, $this->whoAmI(self::PROXY, ['X-Forwarded-For' => '6.6.6.6, '.self::CLIENT.', 10.1.9.9'])['ip']);
    }

    public function test_a_malformed_forwarded_header_does_not_crash_and_never_yields_a_spoofed_address(): void
    {
        $this->trust(['10.1.0.0/16']);

        foreach (['not-an-ip', '', '999.999.1.1', '1.2.3.4, <script>', str_repeat('1.1.1.1, ', 200)] as $header) {
            $ip = $this->whoAmI(self::PROXY, ['X-Forwarded-For' => $header])['ip'];
            $this->assertNotSame('999.999.1.1', $ip);
            $this->assertNotSame('<script>', $ip);
        }
    }

    public function test_invalid_configuration_trusts_nothing_even_if_the_environment_asked_for_everything(): void
    {
        // What config/trustedproxy.php produces from TRUSTED_PROXIES='*' (or 0.0.0.0/0): an empty list.
        $this->trust(TrustedProxies::parse('*')['proxies']);

        $this->assertSame(self::PROXY, $this->whoAmI(self::PROXY, ['X-Forwarded-For' => '1.2.3.4'])['ip']);

        $this->trust(TrustedProxies::parse('0.0.0.0/0')['proxies']);
        $this->assertSame(self::PROXY, $this->whoAmI(self::PROXY, ['X-Forwarded-For' => '1.2.3.4'])['ip']);
    }

    public function test_only_the_four_forwarded_headers_the_app_needs_are_honoured(): void
    {
        $this->trust(['10.1.0.0/16']);

        $kernel = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('HEADER_X_FORWARDED_FOR', $kernel);
        $this->assertStringNotContainsString('HEADER_X_FORWARDED_AWS_ELB', $kernel);
        $this->assertStringNotContainsString('HEADER_X_FORWARDED_PREFIX', $kernel);

        // The AWS-ELB style header is not a way to set the address.
        $this->assertSame(self::PROXY, $this->whoAmI(self::PROXY, ['X-Forwarded-Aws-Elb-Client-Ip' => '1.2.3.4'])['ip']);
    }

    // ------------------------------------------------------------------------------------- key shape

    public function test_ipv4_keys_are_the_address_and_ipv6_keys_the_slash_64_prefix(): void
    {
        $this->assertSame('198.51.100.7', ClientIpKey::of('198.51.100.7'));
        $this->assertSame(ClientIpKey::of('2001:db8:1:2::1'), ClientIpKey::of('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        $this->assertSame(ClientIpKey::of('2001:db8:1:2::1'), ClientIpKey::of('2001:0DB8:0001:0002:0:0:0:9'), 'notation does not matter');
        $this->assertNotSame(ClientIpKey::of('2001:db8:1:2::1'), ClientIpKey::of('2001:db8:1:3::1'));
        $this->assertStringStartsWith('v6:', ClientIpKey::of('2001:db8:1:2::1'));
    }

    public function test_unparseable_or_missing_addresses_share_one_bucket_which_is_stricter_not_looser(): void
    {
        foreach ([null, '', 'garbage', '999.1.1.1', '2001:db8:::1', "1.2.3.4\n", '1.2.3.4 '] as $ip) {
            $this->assertSame('unknown', ClientIpKey::of($ip), var_export($ip, true));
        }
    }

    // --------------------------------------------------------------- the limiter on the real endpoint

    private function hit(string $remote, array $headers = []): int
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->postJson(route('customer.assistant.message'), ['message' => 'hello'], $headers)->status();
    }

    public function test_rotating_a_spoofed_forwarded_address_does_not_escape_the_per_ip_limit(): void
    {
        $this->trust([]);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(200, $this->hit('203.0.113.50', ['X-Forwarded-For' => "9.9.9.{$i}"]), "request {$i}");
        }

        $this->assertSame(429, $this->hit('203.0.113.50', ['X-Forwarded-For' => '9.9.9.200']), 'a fresh spoofed address is still the same client');
        $this->assertSame(200, $this->hit('203.0.113.51'), 'a genuinely different address has its own budget');
    }

    public function test_behind_a_trusted_proxy_each_real_client_has_its_own_budget_and_a_spoofed_prefix_does_not_help(): void
    {
        $this->trust(['10.1.0.0/16']);

        for ($i = 1; $i <= 10; $i++) {
            // The client rotates what it writes at the front of the header; the proxy appends the true address.
            $this->assertSame(200, $this->hit(self::PROXY, ['X-Forwarded-For' => "9.9.9.{$i}, ".self::CLIENT]));
        }

        $this->assertSame(429, $this->hit(self::PROXY, ['X-Forwarded-For' => '9.9.9.99, '.self::CLIENT]));
        $this->assertSame(200, $this->hit(self::PROXY, ['X-Forwarded-For' => '198.51.100.8']), 'another real client behind the same proxy is unaffected');
    }

    public function test_one_proxy_without_forwarding_is_one_shared_client_never_an_unlimited_one(): void
    {
        $this->trust(['10.1.0.0/16']);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(200, $this->hit(self::PROXY));
        }

        $this->assertSame(429, $this->hit(self::PROXY));
    }

    public function test_an_ipv6_client_cannot_rotate_addresses_inside_its_prefix_to_dodge_the_limit(): void
    {
        $this->trust([]);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(200, $this->hit(sprintf('2001:db8:aaaa:bbbb::%x', $i)));
        }

        $this->assertSame(429, $this->hit('2001:db8:aaaa:bbbb:1234:5678:9abc:def0'));
        $this->assertSame(200, $this->hit('2001:db8:aaaa:cccc::1'), 'a different /64 is a different client');
    }

    public function test_the_hourly_ceiling_is_keyed_the_same_way(): void
    {
        $this->trust([]);
        $limiter = app(RateLimiter::class);
        $limits = ($limiter->limiter('customer-assistant'))(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.60']));

        $this->assertCount(2, $limits);
        $this->assertSame('customer-assistant:ip-min:203.0.113.60', $limits[0]->key);
        $this->assertSame('customer-assistant:ip-hour:203.0.113.60', $limits[1]->key);

        $v6 = ($limiter->limiter('customer-assistant'))(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '2001:db8:aaaa:bbbb::5']));
        $this->assertSame('customer-assistant:ip-min:'.ClientIpKey::of('2001:db8:aaaa:bbbb::1'), $v6[0]->key);
    }
}
