<?php

namespace App\Core\Tenancy\Events;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A company was created, by sign-up or through the API (TEN-03).
 * Dispatched inside the creating transaction and tenant context, so
 * listeners (country pack tax codes, CP-01) commit or roll back with it.
 */
class CompanyCreated
{
    use Dispatchable;

    public function __construct(public readonly Company $company) {}
}
