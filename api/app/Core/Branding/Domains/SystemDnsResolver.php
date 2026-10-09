<?php

namespace App\Core\Branding\Domains;

/** BR-05: TXT lookups through the system resolver (PHP's dns_get_record). */
class SystemDnsResolver implements DnsResolver
{
    public function txt(string $name): ?array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return null;
        }

        $values = [];

        foreach ($records as $record) {
            // Long TXT values come split into strings of 255 characters at most.
            $values[] = isset($record['entries']) && is_array($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? '');
        }

        return $values;
    }
}
