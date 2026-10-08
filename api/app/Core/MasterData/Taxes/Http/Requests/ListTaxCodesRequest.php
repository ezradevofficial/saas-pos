<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Http\Requests\ListsArchivable;

/** MD-03: a company's tax codes with their rates; `?status`, `?per_page`. */
class ListTaxCodesRequest extends CompanyTaxRequest
{
    use ListsArchivable;

    public function rules(): array
    {
        return $this->listRules();
    }
}
