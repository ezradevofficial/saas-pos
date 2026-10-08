<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/** MD-03: a company's price lists (`core.price_list.view|edit`, company scope). */
abstract class CompanyPriceListRequest extends CompanyResourceRequest
{
    protected string $resource = 'price_list';

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }
}
