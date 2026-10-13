<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

/** SUB-01: a new annual discount ("2 months free" is 10 charged for 12) from a date. */
class StoreAnnualDiscountRequest extends ManageCatalogueRequest
{
    public function rules(): array
    {
        return [
            'months_in_cycle' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'months_charged' => ['required', 'integer', 'min:1', 'lte:'.(int) $this->input('months_in_cycle', 12)],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
        ];
    }
}
