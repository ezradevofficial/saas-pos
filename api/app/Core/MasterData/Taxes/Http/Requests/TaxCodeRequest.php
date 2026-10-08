<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;

/** MD-03: one tax code, checked at its company's scope (`core.tax.view|edit`). */
class TaxCodeRequest extends CompanyResourceRequest
{
    protected string $resource = 'tax';

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->taxCode()->company_id);
    }

    protected function taxCode(): TaxCode
    {
        return $this->route('tax_code');
    }
}
