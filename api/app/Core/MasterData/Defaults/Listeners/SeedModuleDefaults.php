<?php

namespace App\Core\MasterData\Defaults\Listeners;

use App\Core\MasterData\Defaults\ModuleDefaults;
use App\Core\Rbac\Events\ModuleActivated;
use App\Core\Tenancy\TenantContext;

/** ONB-03, RBAC-08: a module switched on gets the defaults it declared, in every live company, once committed. */
class SeedModuleDefaults
{
    public function __construct(private readonly TenantContext $tenants, private readonly ModuleDefaults $defaults) {}

    public function handle(ModuleActivated $event): void
    {
        if ($this->defaults->for($event->module) === []) {
            return;
        }

        $this->tenants->run($event->tenantId, fn () => $this->defaults->seedTenant($event->module));
    }
}
