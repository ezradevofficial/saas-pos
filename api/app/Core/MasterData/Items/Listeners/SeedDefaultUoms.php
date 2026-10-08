<?php

namespace App\Core\MasterData\Items\Listeners;

use App\Core\MasterData\Items\DefaultUoms;
use App\Core\Tenancy\Events\TenantProvisioned;

/** MD-02: a new tenant gets the default units. Runs inside the sign-up transaction and tenant context. */
class SeedDefaultUoms
{
    public function __construct(private readonly DefaultUoms $uoms) {}

    public function handle(TenantProvisioned $event): void
    {
        $this->uoms->seed();
    }
}
