<?php

namespace App\Core\MasterData\Prices\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;

/** TEN-06: archive or restore one price (`core.price.edit` at its list's company). No body. */
class ItemPriceActionRequest extends CompanyResourceRequest
{
    use FreezesPrices;

    protected string $resource = 'price';

    protected bool $edits = true;

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail(PriceList::query()->whereKey($this->itemPrice()->price_list_id)->value('company_id'));
    }

    public function itemPrice(): ItemPrice
    {
        return $this->route('item_price');
    }
}
