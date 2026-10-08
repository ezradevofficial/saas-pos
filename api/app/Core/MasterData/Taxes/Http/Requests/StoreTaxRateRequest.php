<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use Brick\Math\BigDecimal;
use Illuminate\Validation\Validator;

/**
 * CP-02: a new rate of a tax code from `effective_from`, a percentage
 * (12.5 = 12.5 %). It closes the previous rate the day before.
 */
class StoreTaxRateRequest extends TaxCodeRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return [
            'rate' => ['required', 'string', ...TaxCodeRules::RATE],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->taxCode()->kind === 'zero_rated' && ! BigDecimal::of((string) $this->input('rate'))->isZero()) {
                $validator->errors()->add('rate', __('core.tax.zero_rated_rate'));
            }
        }];
    }

    public function attributes(): array
    {
        return TaxCodeRules::attributes();
    }
}
