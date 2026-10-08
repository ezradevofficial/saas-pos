<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** MD-03: rename a price list, change its currency, tax inclusion or default flag. */
class UpdatePriceListRequest extends PriceListRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return PriceListRules::rules(updating: true);
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
