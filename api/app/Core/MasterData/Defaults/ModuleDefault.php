<?php

namespace App\Core\MasterData\Defaults;

use App\Core\Tenancy\Models\Company;

/**
 * ONB-03, MD-03: what a company needs in core master data before a module
 * can be used (price list, tax categories, payment methods ...). Registered
 * for the modules that need it with ModuleDefaults. An implementation adds
 * only what is missing, never changes what the tenant has, and is safe to
 * run again. Called inside the tenant's context and a transaction.
 */
interface ModuleDefault
{
    /** Seed $company's missing defaults; returns how many records were created. */
    public function seed(Company $company): int;
}
