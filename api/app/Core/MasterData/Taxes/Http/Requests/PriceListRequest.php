<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;

/** MD-03: one price list, checked at its company's scope (`core.price_list.view|edit`). */
class PriceListRequest extends CompanyResourceRequest
{
    protected string $resource = 'price_list';

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->priceList()->company_id);
    }

    protected function priceList(): PriceList
    {
        return $this->route('price_list');
    }
}
