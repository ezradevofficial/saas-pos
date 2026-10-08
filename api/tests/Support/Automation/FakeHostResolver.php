<?php

namespace Tests\Support\Automation;

use App\Core\Automation\Webhooks\HostResolver;

/**
 * DNS answers for webhook tests: host => list of answers, one per lookup
 * (the last one repeats), so a host whose answer changes between lookups
 * (DNS rebinding) can be simulated. Counts lookups per host.
 */
class FakeHostResolver implements HostResolver
{
    /** @var array<string, int> */
    public array $lookups = [];

    /** @param array<string, list<list<string>>> $answers */
    public function __construct(private array $answers = []) {}

    public function resolve(string $host): array
    {
        $n = $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;
        $answers = $this->answers[$host] ?? [[]];

        return $answers[min($n, count($answers)) - 1];
    }
}
