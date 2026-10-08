<?php

namespace App\Core\MasterData\Prices\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;

/**
 * MD-03 follow-up: the prices of one price list, checked at its company's
 * scope (`core.price.view|edit`; CompanyResourceRequest): a company out of
 * sight is not found, one in sight without the permission is forbidden.
 */
abstract class PriceListPricesRequest extends CompanyResourceRequest
{
    use FreezesPrices;

    protected string $resource = 'price';

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->priceList()->company_id);
    }

    public function priceList(): PriceList
    {
        return $this->route('price_list');
    }
}
