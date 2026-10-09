<?php

namespace Tests\Support\Branding;

use App\Core\Branding\Domains\DnsResolver;

/** BR-05: TXT records the test publishes; a name in $failing answers a lookup failure. */
class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    /** @var list<string> */
    public array $failing = [];

    /** @var list<string> names looked up, in order */
    public array $asked = [];

    public function publish(string $name, string $value): void
    {
        $this->records[$name][] = $value;
    }

    public function txt(string $name): ?array
    {
        $this->asked[] = $name;

        return in_array($name, $this->failing, true) ? null : ($this->records[$name] ?? []);
    }
}
