<?php

namespace Tests\Support\Limits;

use App\Core\Limits\Allowances;
use App\Core\Limits\PlanAllowances;

/** LIM-01: allowances a test sets per tenant (unlimited for others), standing in for billing. */
final class FixedAllowances implements PlanAllowances
{
    /** @var array<string, Allowances> */
    public array $byTenant = [];

    public function set(string $tenantId, Allowances $allowances): void
    {
        $this->byTenant[$tenantId] = $allowances;
    }

    public function for(string $tenantId): Allowances
    {
        return $this->byTenant[$tenantId] ?? Allowances::unlimited();
    }
}
