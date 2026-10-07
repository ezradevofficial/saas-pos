<?php

namespace App\Core\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * An entry of the global permission catalogue, `module.resource.action`
 * (RBAC-01). Written only by `permissions:sync` from PermissionRegistry.
 */
class Permission extends SpatiePermission
{
    use HasUuids;

    public const GUARD = 'web';

    protected $fillable = ['name', 'guard_name', 'module', 'resource', 'action'];
}
