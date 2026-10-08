<?php

namespace App\Core\MasterData\Items;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/**
 * RBAC-04 for units of measure (MD-02). Units are the tenant's, shared by
 * every company: read with `core.uom.view` (or `edit`) at any scope;
 * created, changed, archived and restored with `core.uom.edit` at tenant
 * scope, since a change reaches every company.
 */
class UomPolicy
{
    public const PERMISSIONS = ['core.uom.view', 'core.uom.edit'];

    public function __construct(
        private readonly CompanyReach $reach,
        private readonly ScopeResolver $resolver,
    ) {}

    public function view(User $user, ?Uom $uom = null): bool
    {
        return ($uom === null || $uom->tenant_id === $user->tenant_id) && $this->reach->anywhere($user, self::PERMISSIONS);
    }

    public function edit(User $user, ?Uom $uom = null): bool
    {
        return ($uom === null || $uom->tenant_id === $user->tenant_id) && $this->resolver->can($user, 'core.uom.edit', Scope::tenant());
    }
}
