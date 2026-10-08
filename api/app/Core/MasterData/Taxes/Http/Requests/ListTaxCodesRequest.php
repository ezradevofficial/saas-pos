<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Taxes\Http\Lists\TaxCodeList;

/**
 * MD-03: a company's tax codes with their rates; `?status`, `?per_page`,
 * `?search=` (code, name or fiscal code), `?sort` and an export
 * (`?format`, `?columns[]`; TaxCodeList, EXP-01).
 */
class ListTaxCodesRequest extends CompanyTaxRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new TaxCodeList($this->route('company'));
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
