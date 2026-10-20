<?php

namespace App\Core\Platform\Tenants;

use Closure;

/**
 * ADM-01: counters a module adds to a tenant's health in the console, e.g.
 * Accounting's open posting exceptions. Each probe runs in the tenant's
 * own context (row-level security) and returns a count only, never a
 * record. A probe of a module the tenant has switched off is skipped.
 */
class TenantHealthProbes
{
    /** @var array<string, array{module: string, count: Closure(): int}> */
    private array $probes = [];

    /** @param  Closure(): int  $count  run in the tenant's context */
    public function register(string $key, string $module, Closure $count): void
    {
        $this->probes[$key] = ['module' => $module, 'count' => $count];
    }

    /** @return array<string, array{module: string, count: Closure(): int}> */
    public function all(): array
    {
        return $this->probes;
    }
}
