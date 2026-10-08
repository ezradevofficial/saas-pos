<?php

namespace App\Core\MasterData\Prices\Http\Requests;

/**
 * MD-03 follow-up: set one price (`core.price.edit` at the list's company):
 * the active price with the same item, unit, start date and quantity break
 * gets the amount, else a price is added (PriceRules, PriceWriter).
 */
class SetItemPriceRequest extends PriceListPricesRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return PriceRules::rules();
    }

    public function messages(): array
    {
        return PriceRules::messages();
    }

    public function attributes(): array
    {
        return PriceRules::attributes();
    }
}
