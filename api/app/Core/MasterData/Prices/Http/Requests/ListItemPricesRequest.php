<?php

namespace App\Core\MasterData\Prices\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Prices\Http\Lists\ItemPriceList;

/**
 * MD-03 follow-up: a price list's prices; `?status`, `?per_page`,
 * `?search=` (item code or name, unit code),
 * `?state=current|scheduled|replaced`, `?sort` and an export (`?format`,
 * `?columns[]`; ItemPriceList, EXP-01).
 */
class ListItemPricesRequest extends PriceListPricesRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new ItemPriceList($this->priceList());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'in:current,scheduled,replaced'],
        ];
    }
}
