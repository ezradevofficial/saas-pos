<?php

namespace App\Core\Automation\Webhooks;

/** HostResolver through the system's DNS (A and AAAA records). */
class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }
}
