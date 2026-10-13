<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;

/** SUB-01: a new price row from a date (today or later); prices are never edited. */
class StoreCataloguePriceRequest extends ManageCatalogueRequest
{
    public const MAX_AMOUNT = 1_000_000_000_000;

    public function rules(): array
    {
        $type = $this->input('item_type');

        return [
            'item_type' => ['required', Rule::in(['module', 'bundle'])],
            'item_key' => ['required', 'string', 'max:40', Rule::exists($type === 'bundle' ? 'catalogue_bundles' : 'catalogue_modules', 'key')->whereNull('archived_at')],
            'country' => ['required', 'string', 'size:2', Rule::exists('catalogue_countries', 'country')],
            'amount' => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'minimum_amount' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT, Rule::prohibitedIf($type === 'bundle')],
            'extra_outlet_amount' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT, Rule::prohibitedIf($type !== 'bundle')],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
        ];
    }
}
