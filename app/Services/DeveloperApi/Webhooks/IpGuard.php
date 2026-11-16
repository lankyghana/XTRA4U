<?php

namespace App\Services\DeveloperApi\Webhooks;

/**
 * Decides whether an IP address is a safe PUBLIC destination for an outbound
 * webhook. Fails closed: anything that cannot be parsed, or that is not
 * positively public, is unsafe.
 *
 *  IPv4  : public unless inside a special-use range (RFC 1918, loopback,
 *          link-local incl. cloud metadata 169.254.169.254, CGNAT, test nets,
 *          benchmarking, multicast, reserved, broadcast, "this network").
 *  IPv6  : an ALLOW-list — only global unicast 2000::/3 qualifies, minus the
 *          special ranges inside it (Teredo/ORCHID/documentation 2001::/23 and
 *          2001:db8::/32, 6to4 2002::/16, 3fff::/20). So ::1, ::, fc00::/7
 *          (incl. AWS fd00:ec2::254), fe80::/10, ff00::/8, IPv4-mapped
 *          (::ffff:0:0/96), NAT64 and everything else is rejected.
 */
final class IpGuard
{
    /** @var list<string> */
    private const V4_DENY = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const V6_DENY_WITHIN_GLOBAL = [
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::V4_DENY as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (! self::inCidr($packed, '2000::/3')) {
            return false;
        }

        foreach (self::V6_DENY_WITHIN_GLOBAL as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    public static function isIpLiteral(string $host): bool
    {
        $h = trim($host, '[]');

        return filter_var($h, FILTER_VALIDATE_IP) !== false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = inet_pton($net);
        $bits = (int) $bits;

        if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($netPacked, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($netPacked[$fullBytes]) & $mask);
    }
}
