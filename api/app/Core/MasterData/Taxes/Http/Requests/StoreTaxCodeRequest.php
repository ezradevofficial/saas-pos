<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Taxes\TaxCode;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Validator;

/**
 * MD-03: the tenant's own tax code. Its first rate starts on
 * `effective_from` (not for exempt codes); `rate` may be left out or null
 * ("Rate needed"); a zero-rated code's rate is 0.
 */
class StoreTaxCodeRequest extends CompanyTaxRequest
{
    protected bool $edits = true;

    protected function prepareForValidation(): void
    {
        TaxCodeRules::normaliseCode($this);
    }

    public function rules(): array
    {
        return [
            ...TaxCodeRules::rules($this->route('company')->id, null),
            'kind' => ['required', 'string', 'in:'.implode(',', TaxCode::KINDS)],
            'rate' => ['sometimes', 'nullable', 'string', ...TaxCodeRules::RATE],
            'effective_from' => ['exclude_if:kind,exempt', 'required', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('kind') === 'exempt' && $this->filled('rate')) {
                $validator->errors()->add('rate', __('core.tax.exempt_has_no_rate'));
            }

            if ($this->input('kind') === 'zero_rated' && $this->filled('rate') && ! BigDecimal::of((string) $this->input('rate'))->isZero()) {
                $validator->errors()->add('rate', __('core.tax.zero_rated_rate'));
            }
        }];
    }

    public function attributes(): array
    {
        return TaxCodeRules::attributes();
    }
}
