<?php

namespace App\Core\Automation\Webhooks;

/**
 * Looks up the addresses of a webhook's host name (A and AAAA). Bound in
 * the container so tests can answer without DNS (and simulate a host
 * whose answer changes between lookups: DNS rebinding).
 */
interface HostResolver
{
    /** @return list<string> IPv4 and IPv6 addresses; empty when the name does not resolve */
    public function resolve(string $host): array;
}
