<?php

namespace App\Core\MasterData\Prices\Http\Requests;

/**
 * MD-03 follow-up: set up to 500 prices at once, all or nothing
 * (`core.price.edit` at the list's company): `{prices: [PriceRules...]}`.
 * Errors name the row: `prices.3.uom_id`.
 */
class BulkSetItemPricesRequest extends PriceListPricesRequest
{
    public const MAX = 500;

    protected bool $edits = true;

    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX],
            'prices.*' => ['required', 'array:item_id,uom_id,amount_minor,currency,effective_from,min_quantity'],
            ...PriceRules::rules('prices.*.'),
        ];
    }

    public function messages(): array
    {
        return PriceRules::messages('prices.*.');
    }

    public function attributes(): array
    {
        return ['prices' => __('core.price.attributes.prices'), ...PriceRules::attributes('prices.*.')];
    }
}
