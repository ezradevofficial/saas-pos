<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use Illuminate\Validation\Validator;

/** MD-03: rename a tax category or change its default tax codes. Whether it is shared does not change here (TEN-08). */
class UpdateTaxCategoryRequest extends TaxCategoryRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...TaxCategoryRules::codeRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => TaxCategoryRules::validateCodes(
            $validator, (array) $this->input('codes', []), $this->category()->company_id, $this->user(),
        )];
    }

    public function attributes(): array
    {
        return TaxCategoryRules::attributes();
    }
}
