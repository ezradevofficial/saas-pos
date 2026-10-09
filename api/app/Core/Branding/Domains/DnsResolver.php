<?php

namespace App\Core\Branding\Domains;

/**
 * BR-05: reads DNS TXT records. Bound to SystemDnsResolver; tests bind a
 * fake so no test touches the network.
 */
interface DnsResolver
{
    /**
     * The TXT strings published at $name, or null when the lookup itself
     * failed (a timeout or a server error: try again later). A name with
     * no TXT records answers [].
     *
     * @return list<string>|null
     */
    public function txt(string $name): ?array;
}
