<?php

namespace App\Core\Tenancy\Events;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant and its owner were created (sign-up). Dispatched inside the
 * sign-up transaction and tenant context, so listeners (role templates,
 * RBAC-03) commit or roll back with it.
 */
class TenantProvisioned
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $owner,
    ) {}
}
