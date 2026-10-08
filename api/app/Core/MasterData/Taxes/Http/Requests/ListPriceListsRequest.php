<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Taxes\Http\Lists\PriceListList;

/**
 * MD-03: a company's price lists; `?status`, `?per_page`, `?search=` (name
 * or currency), `?sort` and an export (`?format`, `?columns[]`;
 * PriceListList, EXP-01).
 */
class ListPriceListsRequest extends CompanyPriceListRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new PriceListList($this->route('company'));
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
