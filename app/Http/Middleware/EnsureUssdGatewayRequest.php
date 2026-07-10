<?php

namespace App\Http\Middleware;

use App\Models\UssdSubscriptionEvent;
use App\Services\Ussd\UssdConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Authenticates the USSD aggregator before any session work happens.
 *
 * Two independent, optional controls, both admin-configurable:
 *  - an IP allowlist (bare IPs or CIDR blocks)
 *  - a shared secret presented as a header
 *
 * Both default to "off" so an existing deployment keeps working until the
 * values are configured. Leaving them off means anyone on the internet can
 * drive the menu and consume vendors' paid sessions.
 */
class EnsureUssdGatewayRequest
{
    /** Headers a gateway might reasonably use to present the shared secret. */
    private const SECRET_HEADERS = ['X-Ussd-Secret', 'X-Api-Key', 'Authorization'];

    public function __construct(private readonly UssdConfig $config) {}

    public function handle(Request $request, Closure $next)
    {
        $allowlist = $this->config->gatewayIpAllowlist();

        if ($allowlist !== [] && ! IpUtils::checkIp($request->ip(), $allowlist)) {
            return $this->reject($request, 'source IP not allowlisted');
        }

        if ($this->config->hasGatewaySecret() && ! $this->secretMatches($request)) {
            return $this->reject($request, 'invalid or missing gateway secret');
        }

        return $next($request);
    }

    private function secretMatches(Request $request): bool
    {
        $expected = $this->config->gatewaySecret();

        foreach (self::SECRET_HEADERS as $header) {
            $presented = (string) $request->header($header, '');

            if ($presented === '') {
                continue;
            }

            // Tolerate "Bearer <secret>" on the Authorization header.
            $presented = preg_replace('/^Bearer\s+/i', '', $presented);

            // Constant-time compare: a length-or-prefix leak here would let an
            // attacker recover the secret byte by byte.
            if (hash_equals($expected, $presented)) {
                return true;
            }
        }

        return false;
    }

    private function reject(Request $request, string $reason)
    {
        Log::warning('USSD gateway request rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        UssdSubscriptionEvent::create([
            'event' => UssdSubscriptionEvent::SECURITY_EVENT,
            'description' => "USSD gateway request rejected: {$reason}.",
            'context' => ['user_agent' => substr((string) $request->userAgent(), 0, 255)],
            'actor_type' => 'gateway',
            'ip_address' => $request->ip(),
        ]);

        // Plain text, 200: the aggregator renders the body to the caller. A 4xx
        // would surface as a generic network error on the handset instead.
        return response('END This service is not available from your connection.', 200)
            ->header('Content-Type', 'text/plain');
    }
}
