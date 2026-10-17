<?php

namespace App\Http\Middleware;

use App\Models\UssdSubscriptionEvent;
use App\Services\Ussd\GwoSevo\GwoSevoProtocol;
use App\Services\Ussd\UssdConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Authenticates GWO SEVO before any session work happens.
 *
 * Unlike the legacy gateway middleware this FAILS CLOSED: the GWO SEVO guide
 * documents no authentication mechanism, so none is assumed. The endpoint
 * refuses all traffic until the admin configures at least one control, and
 * every configured control must pass:
 *  - an IP allowlist (bare IPs or CIDR blocks), and/or
 *  - a shared secret, presented as the X-Ussd-Secret / X-Api-Key header, a
 *    Bearer token, or - for gateways that can only be given a URL - a `key`
 *    query parameter on the callback URL.
 */
class EnsureGwoSevoGatewayRequest
{
    private const SECRET_HEADERS = ['X-Ussd-Secret', 'X-Api-Key', 'Authorization'];

    public function __construct(private readonly UssdConfig $config) {}

    public function handle(Request $request, Closure $next)
    {
        $allowlist = $this->config->gwoSevoIpAllowlist();
        $hasSecret = $this->config->hasGwoSevoSecret();

        if ($allowlist === [] && ! $hasSecret) {
            return $this->reject($request, 'no gateway authentication configured');
        }

        if ($allowlist !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowlist)) {
            return $this->reject($request, 'source IP not allowlisted');
        }

        if ($hasSecret && ! $this->secretMatches($request)) {
            return $this->reject($request, 'invalid or missing gateway secret');
        }

        return $next($request);
    }

    private function secretMatches(Request $request): bool
    {
        $expected = $this->config->gwoSevoSecret();

        $candidates = [];
        foreach (self::SECRET_HEADERS as $header) {
            $candidates[] = preg_replace('/^Bearer\s+/i', '', (string) $request->header($header, ''));
        }
        $query = $request->query('key');
        $candidates[] = is_string($query) ? $query : '';

        foreach ($candidates as $presented) {
            // Constant-time compare so the secret cannot be recovered byte by byte.
            if ($presented !== '' && hash_equals($expected, $presented)) {
                return true;
            }
        }

        return false;
    }

    private function reject(Request $request, string $reason)
    {
        Log::warning('GWO SEVO gateway request rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        UssdSubscriptionEvent::create([
            'event' => UssdSubscriptionEvent::SECURITY_EVENT,
            'description' => "GWO SEVO gateway request rejected: {$reason}.",
            'context' => ['user_agent' => substr((string) $request->userAgent(), 0, 255)],
            'actor_type' => 'gateway',
            'ip_address' => $request->ip(),
        ]);

        // Never echo an unauthenticated caller's session id.
        return GwoSevoProtocol::end('This service is not available from your connection.');
    }
}
