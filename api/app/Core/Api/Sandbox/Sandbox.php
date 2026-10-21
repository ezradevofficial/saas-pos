<?php

namespace App\Core\Api\Sandbox;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;

/**
 * API-05 (ADR 020): whether the tenant in context is a developer sandbox
 * (`tenants.is_sandbox`, set at sign-up and never changed). A sandbox uses
 * the fake payment and fiscal drivers whatever is configured, is never
 * billed, metered or limited, and is isolated like any tenant. Memoised
 * per tenant id: the flag never changes.
 */
class Sandbox
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function active(): bool
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return false;
        }

        return $this->memo[$tenantId] ??= (bool) Tenant::query()->whereKey($tenantId)->value('is_sandbox');
    }
}
