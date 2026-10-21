<?php

namespace App\Core\Sso\Saml;

/**
 * AUTH-11: the IPv4 and IPv6 addresses a host name resolves to (an IP
 * literal resolves to itself). Tests bind a fake so none touches DNS.
 */
class HostAddresses
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }
}
