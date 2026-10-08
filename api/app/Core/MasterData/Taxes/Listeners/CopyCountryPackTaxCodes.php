<?php

namespace App\Core\MasterData\Taxes\Listeners;

use App\Core\MasterData\Taxes\ApplyCountryPack;
use App\Core\Tenancy\Events\CompanyCreated;

/** CP-01, MD-03: a new company gets its country pack's tax codes, in the creating transaction. */
class CopyCountryPackTaxCodes
{
    public function __construct(private readonly ApplyCountryPack $packs) {}

    public function handle(CompanyCreated $event): void
    {
        $this->packs->apply($event->company);
    }
}
