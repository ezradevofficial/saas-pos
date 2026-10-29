<?php

namespace App\Core\MasterData\Defaults\Listeners;

use App\Core\MasterData\Defaults\ModuleDefaults;
use App\Core\Tenancy\Events\CompanyCreated;

/** ONB-03: a company created while a module is on gets that module's declared defaults, in the creating transaction. */
class SeedCompanyModuleDefaults
{
    public function __construct(private readonly ModuleDefaults $defaults) {}

    public function handle(CompanyCreated $event): void
    {
        $this->defaults->seedCompany($event->company);
    }
}
