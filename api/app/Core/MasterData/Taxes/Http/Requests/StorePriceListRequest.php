<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** MD-03: a price list in one currency, tax-inclusive or exclusive; `is_default` replaces the company's default in that currency. */
class StorePriceListRequest extends CompanyPriceListRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return PriceListRules::rules();
    }

    public function messages(): array
    {
        return PriceListRules::messages();
    }

    public function attributes(): array
    {
        return PriceListRules::attributes();
    }
}
