<?php

namespace App\Services\DeveloperApi\Webhooks;

final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        // The system resolver follows CNAME chains the DNS query above may leave unresolved.
        // The UNION is validated, so adding addresses here can only make validation stricter.
        $v4 = @gethostbynamel($host);
        foreach (is_array($v4) ? $v4 : [] as $ip) {
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }
}
