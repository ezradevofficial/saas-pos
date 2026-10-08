<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Http\Requests\ListsArchivable;

/** MD-03: a company's price lists; `?status`, `?per_page`. */
class ListPriceListsRequest extends CompanyPriceListRequest
{
    use ListsArchivable;

    public function rules(): array
    {
        return $this->listRules();
    }
}
