<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/** MD-03: a company's tax codes (`core.tax.view|edit`, company scope). */
abstract class CompanyTaxRequest extends CompanyResourceRequest
{
    protected string $resource = 'tax';

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }
}
