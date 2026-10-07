<?php

namespace App\Core\Rbac\Listeners;

use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Events\TenantProvisioned;

/**
 * RBAC-03: a new tenant gets the system roles, and its owner the Owner role
 * at tenant scope. Runs inside the sign-up transaction and tenant context.
 */
class ProvisionTenantRoles
{
    public function __construct(private readonly RoleTemplates $templates) {}

    public function handle(TenantProvisioned $event): void
    {
        $roles = $this->templates->provision($event->tenant);

        RoleAssignment::create([
            'tenant_id' => $event->tenant->id,
            'user_id' => $event->owner->id,
            'role_id' => $roles->get('owner')->id,
            'scope_type' => Scope::TENANT,
            'scope_id' => $event->tenant->id,
        ]);
    }
}
