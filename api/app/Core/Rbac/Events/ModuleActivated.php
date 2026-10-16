<?php

namespace App\Core\Rbac\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * RBAC-08: a module was switched on for a tenant, raised once the
 * activation has committed, in that tenant's context. Modules that keep
 * defaults for another module's data listen (e.g. Accounting seeds the
 * posting rules of a module it had none for, ACC-24).
 */
class ModuleActivated
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $module,
    ) {}
}
